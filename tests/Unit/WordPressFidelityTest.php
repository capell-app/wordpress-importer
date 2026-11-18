<?php

declare(strict_types=1);

use Capell\Core\Actions\ResolvePublicPageByUrlAction;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Taxonomy;
use Capell\Core\Models\Term;
use Capell\MigrationAssistant\Actions\ExecuteImportRollbackAction;
use Capell\MigrationAssistant\Data\ExternalPageImportTargetData;
use Capell\MigrationAssistant\Data\RollbackExecutionResultData;
use Capell\MigrationAssistant\Models\ImportRollbackReport;
use Capell\MigrationAssistant\Models\ImportSession;
use Capell\Tests\Fixtures\Models\User;
use Capell\WordPressImporter\Actions\ConvertWordPressGutenbergBlocksAction;
use Capell\WordPressImporter\Actions\ExecuteWordPressWxrImportAction;
use Capell\WordPressImporter\Actions\ReadWordPressWxrAction;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;

function wordpressFidelityExport(string $title = 'Fidelity page', string $content = '<p>Original body</p>', string $excerpt = 'Original excerpt'): string
{
    return <<<XML
<?xml version="1.0" encoding="UTF-8" ?>
<rss version="2.0" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/" xmlns:wp="http://wordpress.org/export/1.2/">
    <channel>
        <title>Fidelity site</title>
        <wp:wxr_version>1.2</wp:wxr_version>
        <wp:base_blog_url>https://fidelity.example.test</wp:base_blog_url>
        <wp:author>
            <wp:author_login><![CDATA[writer]]></wp:author_login>
            <wp:author_email><![CDATA[Writer@Example.test]]></wp:author_email>
            <wp:author_display_name><![CDATA[Wanda Writer]]></wp:author_display_name>
        </wp:author>
        <wp:author>
            <wp:author_login><![CDATA[ghost]]></wp:author_login>
            <wp:author_email><![CDATA[ghost@example.test]]></wp:author_email>
        </wp:author>
        <item>
            <title>{$title}</title>
            <link>https://fidelity.example.test/fidelity-page/</link>
            <dc:creator><![CDATA[writer]]></dc:creator>
            <content:encoded><![CDATA[{$content}]]></content:encoded>
            <excerpt:encoded><![CDATA[{$excerpt}]]></excerpt:encoded>
            <category domain="category" nicename="field-notes"><![CDATA[Field notes]]></category>
            <category domain="post_tag" nicename="travel"><![CDATA[Travel]]></category>
            <wp:post_id>501</wp:post_id>
            <wp:post_name>fidelity-page</wp:post_name>
            <wp:post_type>post</wp:post_type>
            <wp:status>publish</wp:status>
            <wp:post_date>2026-05-01 12:00:00</wp:post_date>
        </item>
        <item>
            <title>Ghost authored</title>
            <link>https://fidelity.example.test/ghost-authored/</link>
            <dc:creator><![CDATA[ghost]]></dc:creator>
            <content:encoded><![CDATA[<p>Ghost body</p>]]></content:encoded>
            <wp:post_id>502</wp:post_id>
            <wp:post_name>ghost-authored</wp:post_name>
            <wp:post_type>post</wp:post_type>
            <wp:status>publish</wp:status>
        </item>
    </channel>
</rss>
XML;
}

/**
 * @param  list<string>  $updateFields
 */
function wordpressFidelityImport(string $xml, Site $site, Layout $layout, Blueprint $type, array $updateFields = []): ImportSession
{
    $path = tempnam(sys_get_temp_dir(), 'capell-wxr-fidelity-');
    file_put_contents($path, $xml);

    // Redirects intentionally rejects redirect writes inside a transaction, so suspend the test one.
    DB::commit();

    try {
        return ExecuteWordPressWxrImportAction::run(
            $path,
            new ExternalPageImportTargetData(
                siteId: $site->id,
                layoutId: $layout->id,
                blueprintId: $type->id,
                languageId: (int) $site->language_id,
            ),
            null,
            $updateFields,
        )->session->refresh();
    } finally {
        DB::beginTransaction();
    }
}

/**
 * @return array<string, mixed>
 */
function wordpressFidelitySummary(ImportSession $session, string $key): array
{
    $summary = is_array($session->result_summary) ? $session->result_summary : [];
    $section = $summary[$key] ?? [];

    return is_array($section) ? $section : [];
}

function wordpressFidelityPage(string $title): Page
{
    return Page::query()->withoutGlobalScopes()->where('name', $title)->firstOrFail();
}

beforeEach(function (): void {
    Notification::fake();
    Storage::fake('public');
    $this->actingAsAdmin();
    $this->site = Site::factory()->create();
    $this->layout = Layout::factory()->create();
    $this->type = Blueprint::factory()->page()->create();
});

it('converts Gutenberg blocks to plain HTML and names the blocks it could not express', function (): void {
    $conversion = ConvertWordPressGutenbergBlocksAction::run(<<<'HTML'
<!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Title</h3><!-- /wp:heading -->
<!-- wp:columns --><div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column"><!-- wp:paragraph --><p class="has-text-align-center">Left</p><!-- /wp:paragraph --></div><!-- /wp:column --></div><!-- /wp:columns -->
<!-- wp:embed {"url":"https://video.example.test/watch?v=1"} --><figure class="wp-block-embed"><div class="wp-block-embed__wrapper">https://video.example.test/watch?v=1</div></figure><!-- /wp:embed -->
<!-- wp:acme/slider {"id":4} --><div class="acme-slider">Slides</div><!-- /wp:acme/slider -->
<!-- wp:separator /-->
HTML);

    expect($conversion->html)->toContain('<h3>Title</h3>')
        ->and($conversion->html)->toContain('<p>Left</p>')
        ->and($conversion->html)->not->toContain('wp-block')
        ->and($conversion->html)->toContain('<div class="acme-slider">')
        ->and($conversion->html)->not->toContain('<!--')
        ->and($conversion->html)->toContain('<a href="https://video.example.test/watch?v=1">')
        ->and($conversion->html)->toContain('Slides')
        ->and($conversion->unsupportedBlocks)->toEqualCanonicalizing(['core/embed', 'acme/slider']);
});

it('reads author emails, term slugs and unsupported blocks from the export', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'capell-wxr-fidelity-read-');
    file_put_contents($path, wordpressFidelityExport(content: '<!-- wp:acme/slider --><div>Slides</div><!-- /wp:acme/slider -->'));

    $read = ReadWordPressWxrAction::run($path)->readResult;
    expect($read->metadata['item_errors'])->toBe([]);
    $row = $read->rows[0];

    expect($row['author_email'])->toBe('writer@example.test')
        ->and($row['author_name'])->toBe('Wanda Writer')
        ->and(data_get($row, 'term_slugs.category'))->toBe([['name' => 'Field notes', 'slug' => 'field-notes']])
        ->and(data_get($row, 'term_slugs.post_tag'))->toBe([['name' => 'Travel', 'slug' => 'travel']])
        ->and($row['unsupported_blocks'])->toBe(['acme/slider'])
        ->and($row['post_content'])->toContain('Slides')
        ->and($row['field_hashes'])->toHaveKeys(['title', 'content', 'excerpt']);
});

it('maps categories, tags and authors, never creates users and rolls the terms back', function (): void {
    $writer = User::factory()->create(['email' => 'writer@example.test']);
    $usersBefore = User::query()->count();

    $session = wordpressFidelityImport(wordpressFidelityExport(), $this->site, $this->layout, $this->type);
    $page = wordpressFidelityPage('Fidelity page');
    $ghost = wordpressFidelityPage('Ghost authored');
    $summary = wordpressFidelitySummary($session, 'wordpress_taxonomies_and_authors');

    expect(Taxonomy::query()->where('site_id', $this->site->getKey())->pluck('key')->sort()->values()->all())->toBe(['category', 'tag'])
        ->and($page->terms()->pluck('slug')->sort()->values()->all())->toBe(['field-notes', 'travel'])
        ->and($page->getAttributes()['created_by'] ?? null)->toBe($writer->id)
        ->and($ghost->getAttributes()['created_by'] ?? null)->not->toBe($writer->id)
        ->and(User::query()->count())->toBe($usersBefore)
        ->and($summary['unmatched_authors'])->toBe(['ghost'])
        ->and($summary['terms_created'])->toBe(2);

    $rollback = wordpressFidelityRollback($session, $this->authenticatedUser());

    expect($rollback->rejected)->toBeFalse(json_encode($rollback->toArray(), JSON_THROW_ON_ERROR))
        ->and(collect($rollback->skipped)->where('type', '!=', 'page')->all())->toBe([])
        ->and(Term::query()->count())->toBe(0)
        ->and(Taxonomy::query()->where('site_id', $this->site->getKey())->exists())->toBeFalse();
});

it('reuses an existing taxonomy and term instead of duplicating them', function (): void {
    $taxonomy = Taxonomy::query()->create(['site_id' => $this->site->getKey(), 'key' => 'category', 'name' => 'Topics', 'hierarchical' => true, 'position' => 0]);
    $term = Term::query()->create(['taxonomy_id' => $taxonomy->getKey(), 'slug' => 'field-notes', 'name' => 'Field notes', 'position' => 0]);

    $session = wordpressFidelityImport(wordpressFidelityExport(), $this->site, $this->layout, $this->type);

    expect(Taxonomy::query()->where('site_id', $this->site->getKey())->where('key', 'category')->count())->toBe(1)
        ->and(Term::query()->where('slug', 'field-notes')->count())->toBe(1)
        ->and(wordpressFidelityPage('Fidelity page')->terms()->pluck('terms.id')->all())->toContain($term->getKey())
        ->and(wordpressFidelitySummary($session, 'wordpress_taxonomies_and_authors')['taxonomies_created'] ?? null)->toBe(1)
        ->and(wordpressFidelitySummary($session, 'wordpress_taxonomies_and_authors')['terms_created'] ?? null)->toBe(1);
});

function wordpressFidelityRollback(ImportSession $session, User $actor): RollbackExecutionResultData
{
    $actor->givePermissionTo(Permission::findOrCreate('import-session.rollback', 'web'));

    return ExecuteImportRollbackAction::run(ImportRollbackReport::query()->where('import_session_id', $session->getKey())->latest('id')->firstOrFail(), actor: $actor);
}

it('rolls back exactly the terms an import created inside an existing taxonomy', function (): void {
    $taxonomy = Taxonomy::query()->create(['site_id' => $this->site->getKey(), 'key' => 'category', 'name' => 'Topics', 'hierarchical' => true, 'position' => 0]);
    $kept = Term::query()->create(['taxonomy_id' => $taxonomy->getKey(), 'slug' => 'archive', 'name' => 'Archive', 'position' => 0]);
    $other = Term::query()->create(['taxonomy_id' => $taxonomy->getKey(), 'slug' => 'unrelated', 'name' => 'Unrelated', 'position' => 1]);

    $session = wordpressFidelityImport(wordpressFidelityExport(), $this->site, $this->layout, $this->type);
    expect(Term::query()->where('slug', 'field-notes')->firstOrFail()->taxonomy_id)->toBe($taxonomy->getKey());

    $rollback = wordpressFidelityRollback($session, $this->authenticatedUser());

    expect($rollback->rejected)->toBeFalse(json_encode($rollback->toArray(), JSON_THROW_ON_ERROR))
        ->and(collect($rollback->skipped)->where('type', '!=', 'page')->all())->toBe([])
        ->and(Term::query()->where('slug', 'field-notes')->exists())->toBeFalse()
        ->and(Term::query()->where('slug', 'travel')->exists())->toBeFalse()
        ->and(Taxonomy::query()->where('key', 'tag')->exists())->toBeFalse()
        ->and(Term::query()->whereKey([$kept->getKey(), $other->getKey()])->count())->toBe(2)
        ->and(Taxonomy::query()->whereKey($taxonomy->getKey())->exists())->toBeTrue();
});

it('keeps a term and its taxonomy when a page outside the import still uses the term', function (): void {
    $session = wordpressFidelityImport(wordpressFidelityExport(), $this->site, $this->layout, $this->type);
    $term = Term::query()->where('slug', 'travel')->firstOrFail();
    $editorPage = Page::factory()->create(['site_id' => $this->site->getKey()]);
    $term->pages()->attach($editorPage->getKey(), ['position' => 0]);

    $rollback = wordpressFidelityRollback($session, $this->authenticatedUser());

    expect(Term::query()->whereKey($term->getKey())->exists())->toBeTrue()
        ->and(Taxonomy::query()->whereKey($term->taxonomy_id)->exists())->toBeTrue()
        ->and($term->pages()->whereKey($editorPage->getKey())->exists())->toBeTrue()
        ->and(collect($rollback->skipped)->pluck('reason')->all())->toContain('delete_failed');
});

it('updates only the fields an editor has not changed and reports the ones they have', function (): void {
    wordpressFidelityImport(wordpressFidelityExport(), $this->site, $this->layout, $this->type);
    $page = wordpressFidelityPage('Fidelity page');
    // An editor rewrites the body in the admin.
    $page->translations()->orderBy('id')->firstOrFail()->forceFill(['content' => '<p>Editor rewrite</p>'])->save();

    $session = wordpressFidelityImport(
        wordpressFidelityExport('Fidelity page renamed', '<p>Source changed body</p>', 'New excerpt'),
        $this->site,
        $this->layout,
        $this->type,
        ['title', 'content', 'excerpt'],
    );

    $page->refresh();
    $report = wordpressFidelitySummary($session, 'wordpress_field_updates');

    expect($page->name)->toBe('Fidelity page renamed')
        ->and(data_get($page->meta, 'excerpt'))->toBe('New excerpt')
        ->and($page->translations()->orderBy('id')->firstOrFail()->content)->toBe('<p>Editor rewrite</p>')
        ->and($report['updated_fields'] ?? null)->toBe(2)
        ->and($report['edited_fields'] ?? null)->toBe(1)
        ->and(Arr::get($report, 'pages.0.skipped'))->toBe(['content' => 'edited'])
        ->and(Page::query()->withoutGlobalScopes()->where('meta->wordpress->source_identity', 'wordpress:501')->count())->toBe(1);
});

it('leaves existing pages alone unless an update is requested, and never changes their address or status', function (): void {
    wordpressFidelityImport(wordpressFidelityExport(), $this->site, $this->layout, $this->type);
    $before = wordpressFidelityPage('Fidelity page');
    $visibleFrom = $before->getRawOriginal('visible_from');

    $plain = wordpressFidelityImport(wordpressFidelityExport('Fidelity page renamed'), $this->site, $this->layout, $this->type);
    expect(wordpressFidelityPage('Fidelity page')->name)->toBe('Fidelity page')
        ->and($plain->result_summary)->not->toHaveKey('wordpress_field_updates');

    wordpressFidelityImport(wordpressFidelityExport('Fidelity page renamed'), $this->site, $this->layout, $this->type, ['title']);
    $after = wordpressFidelityPage('Fidelity page renamed');

    expect($after->getRawOriginal('visible_from'))->toBe($visibleFrom)
        ->and(data_get($after->meta, 'slug'))->toBe(data_get($before->meta, 'slug'));
});

it('does not update a page imported before baselines were recorded', function (): void {
    wordpressFidelityImport(wordpressFidelityExport(), $this->site, $this->layout, $this->type);
    $page = wordpressFidelityPage('Fidelity page');
    $meta = is_array($page->meta) ? $page->meta : [];
    Arr::forget($meta, 'wordpress.stored_hashes');
    $page->forceFill(['meta' => $meta])->saveQuietly();

    $session = wordpressFidelityImport(wordpressFidelityExport('Fidelity page renamed'), $this->site, $this->layout, $this->type, ['title']);

    expect(wordpressFidelityPage('Fidelity page')->name)->toBe('Fidelity page')
        ->and(Arr::get(wordpressFidelitySummary($session, 'wordpress_field_updates'), 'pages.0.skipped'))->toBe(['title' => 'no_baseline']);
});

it('rejects unknown update fields at the command', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'capell-wxr-fidelity-cmd-');
    file_put_contents($path, wordpressFidelityExport());

    expect(fn (): int => Artisan::call('wordpress-importer:import', [
        'path' => $path, '--execute' => true, '--site-id' => $this->site->getKey(), '--layout-id' => $this->layout->getKey(),
        '--type-id' => $this->type->getKey(), '--actor-id' => $this->authenticatedUser()->getKey(), '--update-fields' => 'slug',
    ]))->toThrow(RuntimeException::class, 'slug');
    expect(Page::query()->withoutGlobalScopes()->where('site_id', $this->site->getKey())->exists())->toBeFalse();
});

it('creates one address per imported page and a rollback leaves no imported pages or addresses behind', function (): void {
    $session = wordpressFidelityImport(wordpressFidelityExport(), $this->site, $this->layout, $this->type);
    $pageIds = Page::query()->withoutGlobalScopes()->where('meta->wordpress->import_session_uuid', $session->uuid)->pluck('id')->all();

    expect($pageIds)->toHaveCount(2)
        ->and(DB::table('page_urls')->whereIn('pageable_id', $pageIds)->where('pageable_type', 'page')->count())->toBe(2)
        ->and(DB::table('page_urls')->whereIn('pageable_id', $pageIds)->select('url')->distinct()->count('url'))->toBe(2);

    $resolves = fn (string $url): bool => ResolvePublicPageByUrlAction::run($this->site, $this->site->language, $url)->page !== null;

    expect($resolves('/fidelity-page'))->toBeTrue();

    $rollback = wordpressFidelityRollback($session, $this->authenticatedUser());

    expect($rollback->rejected)->toBeFalse()
        ->and($rollback->skipped)->toBe([])
        ->and(Page::query()->whereIn('id', $pageIds)->count())->toBe(0)
        ->and(DB::table('page_urls')->whereIn('pageable_id', $pageIds)->where('pageable_type', 'page')->whereNull('deleted_at')->count())->toBe(0)
        ->and($resolves('/fidelity-page'))->toBeFalse()
        ->and($resolves('/ghost-authored'))->toBeFalse();
});
