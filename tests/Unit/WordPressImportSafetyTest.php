<?php

declare(strict_types=1);

use Capell\Core\Enums\PublishVisibilityStateEnum;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\MigrationAssistant\Contracts\MigrationAssistantRowContributor;
use Capell\MigrationAssistant\Data\ExternalPageImportTargetData;
use Capell\MigrationAssistant\Enums\ImportSessionStatus;
use Capell\MigrationAssistant\Models\ImportRollbackReport;
use Capell\Tests\Fixtures\Models\User;
use Capell\WordPressImporter\Actions\ExecuteWordPressWxrImportAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    Notification::fake();
    Role::findOrCreate('super_admin', 'web');
    $this->actingAs(User::factory()->create()->assignRole('super_admin'));
});

/**
 * @param  list<string>  $items
 */
function wordpressSafetyExport(array $items, string $baseSiteUrl = 'https://blog.example.test'): string
{
    $path = tempnam(sys_get_temp_dir(), 'capell-wxr-safety-');
    throw_unless(is_string($path), RuntimeException::class, 'Expected a temporary WXR path.');

    file_put_contents($path, '<?xml version="1.0" encoding="UTF-8" ?>'
        . '<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/" xmlns:wp="http://wordpress.org/export/1.2/">'
        . '<channel><title>Safety Site</title><wp:wxr_version>1.2</wp:wxr_version>'
        . '<wp:base_site_url>' . $baseSiteUrl . '</wp:base_site_url><wp:base_blog_url>' . $baseSiteUrl . '</wp:base_blog_url>'
        . implode('', $items)
        . '</channel></rss>');

    return $path;
}

/**
 * @return array{0: Site, 1: ExternalPageImportTargetData}
 */
function wordpressSafetyTarget(): array
{
    $site = Site::factory()->create();

    $layout = Layout::factory()->create();
    $blueprint = Blueprint::factory()->page()->create();

    return [$site, new ExternalPageImportTargetData(
        siteId: (int) $site->getKey(),
        layoutId: (int) $layout->getKey(),
        blueprintId: (int) $blueprint->getKey(),
        languageId: (int) $site->language_id,
    )];
}

function wordpressSafetyFailOnBrokenPage(): void
{
    config()->set('wordpress-importer.spool.chunk_rows', 1);
    app()->bind(MigrationAssistantRowContributor::class, fn (): MigrationAssistantRowContributor => new class implements MigrationAssistantRowContributor
    {
        public function extraAttributes(Model $model): array
        {
            return [];
        }

        public function normalizeIncomingRow(array $attributes): array
        {
            throw_if(($attributes['name'] ?? null) === 'Broken page', RuntimeException::class, 'Row could not be written.');

            return $attributes;
        }

        public function scopeExportable(Builder $query): Builder
        {
            return $query;
        }
    });
}

/** @return list<string> */
function wordpressSafetyChunkFailureItems(): array
{
    return [
        '<item><title>Created page</title><wp:post_id>401</wp:post_id><wp:post_name>created</wp:post_name><wp:post_type>page</wp:post_type><wp:status>publish</wp:status></item>',
        '<item><title>Broken page</title><wp:post_id>402</wp:post_id><wp:post_name>broken</wp:post_name><wp:post_type>page</wp:post_type><wp:status>publish</wp:status></item>',
    ];
}

it('imports a password-protected post as unpublished and never stores the password', function (): void {
    [, $target] = wordpressSafetyTarget();
    $path = wordpressSafetyExport([
        '<item><title>Members only</title><wp:post_id>301</wp:post_id><wp:post_name>members-only</wp:post_name>'
        . '<wp:post_type>post</wp:post_type><wp:status>publish</wp:status><wp:post_password>hunter2-secret</wp:post_password>'
        . '<content:encoded><![CDATA[<p>Private body</p>]]></content:encoded></item>',
    ]);

    ExecuteWordPressWxrImportAction::run($path, $target);

    $page = Page::query()->withoutGlobalScopes()->where('name', 'Members only')->firstOrFail();

    expect($page->publishVisibilityState())->not->toBe(PublishVisibilityStateEnum::published)
        ->and(data_get($page->meta, 'wordpress.password_protected'))->toBeTrue()
        ->and(json_encode($page->meta, JSON_THROW_ON_ERROR))->not->toContain('hunter2-secret');
});

it('stores imported post HTML without scripts, event handlers or script URLs', function (): void {
    [, $target] = wordpressSafetyTarget();
    $path = wordpressSafetyExport([
        '<item><title>Hostile post</title><wp:post_id>302</wp:post_id><wp:post_name>hostile-post</wp:post_name>'
        . '<wp:post_type>post</wp:post_type><wp:status>publish</wp:status>'
        . '<content:encoded><![CDATA[<p onclick="steal()">Safe text</p><script>alert(1)</script><a href="javascript:alert(2)">x</a><img src="x" onerror="alert(3)">]]></content:encoded>'
        . '<excerpt:encoded><![CDATA[<p>Summary</p><svg onload="alert(4)"></svg>]]></excerpt:encoded></item>',
    ]);

    ExecuteWordPressWxrImportAction::run($path, $target);

    $page = Page::query()->withoutGlobalScopes()->where('name', 'Hostile post')->firstOrFail();
    $stored = mb_strtolower(json_encode([$page->meta, $page->translations()->withoutGlobalScopes()->get()->map(fn ($translation): mixed => $translation->getAttribute('content'))->all()], JSON_THROW_ON_ERROR));

    expect($stored)->toContain('safe text')
        ->and($stored)->not->toContain('<script')
        ->and($stored)->not->toContain('onclick')
        ->and($stored)->not->toContain('onerror')
        ->and($stored)->not->toContain('onload')
        ->and($stored)->not->toContain('javascript:');
});

it('drops HTML comments and processing instructions that a browser could parse as markup', function (): void {
    [, $target] = wordpressSafetyTarget();
    $path = wordpressSafetyExport([
        '<item><title>Comment trick</title><wp:post_id>303</wp:post_id><wp:post_name>comment-trick</wp:post_name>'
        . '<wp:post_type>post</wp:post_type><wp:status>publish</wp:status>'
        . '<content:encoded><![CDATA[<!-- wp:paragraph --><p>Kept text</p><!-- /wp:paragraph --><!--><img src=x onerror=alert(2)>--><?php echo "pi"; ?><!<!-- joined -->--><img src=x onerror=alert(6)>--><p>After</p>]]></content:encoded>'
        . '<excerpt:encoded><![CDATA[<!--><img src=x onerror=alert(5)>-->Summary]]></excerpt:encoded></item>',
    ]);

    ExecuteWordPressWxrImportAction::run($path, $target);

    $page = Page::query()->withoutGlobalScopes()->where('name', 'Comment trick')->firstOrFail();
    $stored = mb_strtolower(json_encode([$page->meta, $page->translations()->withoutGlobalScopes()->get()->map(fn ($translation): mixed => $translation->getAttribute('content'))->all()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

    expect($stored)->toContain('kept text')
        ->and($stored)->toContain('after')
        ->and($stored)->not->toContain('onerror')
        ->and($stored)->not->toContain('<!-->')
        ->and($stored)->not->toContain('<?php')
        ->and(data_get($page->meta, 'wordpress.raw_content'))->toBeString()->toContain('<!-- wp:paragraph -->');
});

it('marks a failed import as failed when its rollback provenance cannot be recorded', function (): void {
    wordpressSafetyFailOnBrokenPage();
    ImportRollbackReport::creating(static fn (): never => throw new RuntimeException('Rollback store unavailable.'));
    [, $target] = wordpressSafetyTarget();
    $path = wordpressSafetyExport(wordpressSafetyChunkFailureItems());

    $result = ExecuteWordPressWxrImportAction::run($path, $target);

    expect($result->session->refresh()->status)->toBe(ImportSessionStatus::Failed);
});

it('records rollback provenance for pages created before a later chunk fails', function (): void {
    wordpressSafetyFailOnBrokenPage();
    [, $target] = wordpressSafetyTarget();
    $path = wordpressSafetyExport(wordpressSafetyChunkFailureItems());

    $result = ExecuteWordPressWxrImportAction::run($path, $target);
    $created = Page::query()->withoutGlobalScopes()->where('name', 'Created page')->firstOrFail();
    $createdKey = $created->getKey();
    throw_unless(is_int($createdKey) || is_string($createdKey), RuntimeException::class, 'Expected a scalar page key.');
    $rollbackReport = $result->session->rollbackReports()->first();

    expect($result->session->status)->toBe(ImportSessionStatus::Failed)
        ->and($rollbackReport)->not->toBeNull()
        ->and(json_encode($rollbackReport?->getAttributes(), JSON_THROW_ON_ERROR))->toContain((string) $createdKey);
});

it('completes an import whose pages were written when its rollback provenance cannot be recorded', function (): void {
    ImportRollbackReport::creating(static fn (): never => throw new RuntimeException('Rollback store unavailable.'));
    [, $target] = wordpressSafetyTarget();
    $path = wordpressSafetyExport([wordpressSafetyChunkFailureItems()[0]]);

    $result = ExecuteWordPressWxrImportAction::run($path, $target);
    $session = $result->session->refresh();

    expect($session->status)->toBe(ImportSessionStatus::Completed)
        ->and(data_get($session->result_summary, 'rollback_report_missing'))->toBeTrue()
        ->and($session->rollbackReports()->exists())->toBeFalse()
        ->and(Page::query()->withoutGlobalScopes()->where('name', 'Created page')->exists())->toBeTrue();
});

it('logs why imported content was dropped when its markup comments cannot be matched', function (): void {
    $log = Log::spy();
    [, $target] = wordpressSafetyTarget();
    $path = wordpressSafetyExport([
        '<item><title>Heavy comments</title><wp:post_id>303</wp:post_id><wp:post_name>heavy-comments</wp:post_name>'
        . '<wp:post_type>post</wp:post_type><wp:status>publish</wp:status>'
        . '<content:encoded><![CDATA[<p>Body</p><!-- ' . str_repeat('a', 5000) . ']]></content:encoded></item>',
    ]);
    $limit = ini_get('pcre.backtrack_limit');
    $jit = ini_get('pcre.jit');
    ini_set('pcre.jit', '0');
    ini_set('pcre.backtrack_limit', '10');

    try {
        ExecuteWordPressWxrImportAction::run($path, $target);
    } finally {
        ini_set('pcre.backtrack_limit', (string) $limit);
        ini_set('pcre.jit', (string) $jit);
    }

    $log->shouldHaveReceived('warning')
        ->withArgs(static fn (string $message, array $context): bool => str_contains($message, 'markup comments could not be removed') && ($context['pcre_error'] ?? null) === 'Backtrack limit exhausted')
        ->atLeast()->once();
});

it('imports overlapping post ids from different source sites and skips the same source on rerun', function (): void {
    [, $target] = wordpressSafetyTarget();
    $first = wordpressSafetyExport(['<item><title>Source one</title><wp:post_id>900</wp:post_id><wp:post_name>source-one</wp:post_name><wp:post_type>page</wp:post_type><wp:status>publish</wp:status></item>'], 'https://one.example.test');
    $second = wordpressSafetyExport(['<item><title>Source two</title><wp:post_id>900</wp:post_id><wp:post_name>source-two</wp:post_name><wp:post_type>page</wp:post_type><wp:status>publish</wp:status></item>'], 'https://two.example.test');

    expect(ExecuteWordPressWxrImportAction::run($first, $target)->report->pagesCreated)->toBe(1)
        ->and(ExecuteWordPressWxrImportAction::run($second, $target)->report->pagesCreated)->toBe(1)
        ->and(ExecuteWordPressWxrImportAction::run($first, $target)->report->pagesCreated)->toBe(0)
        ->and(ExecuteWordPressWxrImportAction::run($second, $target)->report->pagesCreated)->toBe(0);
});

it('recognises a legacy import by its exact source permalink without shadowing another site', function (): void {
    [, $target] = wordpressSafetyTarget();
    $path = wordpressSafetyExport([
        '<item><title>Legacy page</title><link>https://legacy.example.test/old-page</link><wp:post_id>901</wp:post_id><wp:post_name>legacy-page</wp:post_name><wp:post_type>page</wp:post_type><wp:status>publish</wp:status></item>',
    ], 'https://legacy.example.test');
    ExecuteWordPressWxrImportAction::run($path, $target);
    $page = Page::query()->withoutGlobalScopes()->where('name', 'Legacy page')->sole();
    $meta = $page->meta;
    throw_unless(is_array($meta), RuntimeException::class);
    $wordpressMeta = is_array($meta['wordpress'] ?? null) ? $meta['wordpress'] : [];
    unset($wordpressMeta['source_key']);
    $meta['wordpress'] = $wordpressMeta;
    $page->forceFill(['meta' => $meta])->save();

    expect(ExecuteWordPressWxrImportAction::run($path, $target)->report->pagesCreated)->toBe(0)
        ->and(Page::query()->withoutGlobalScopes()->where('name', 'Legacy page')->count())->toBe(1);
});

it('deduplicates a WordPress source only within its authorised target site', function (): void {
    [, $firstTarget] = wordpressSafetyTarget();
    [, $secondTarget] = wordpressSafetyTarget();
    $path = wordpressSafetyExport([
        '<item><title>Shared source page</title><wp:post_id>902</wp:post_id><wp:post_name>shared-source</wp:post_name><wp:post_type>page</wp:post_type><wp:status>publish</wp:status></item>',
    ]);

    expect(ExecuteWordPressWxrImportAction::run($path, $firstTarget)->report->pagesCreated)->toBe(1)
        ->and(ExecuteWordPressWxrImportAction::run($path, $secondTarget)->report->pagesCreated)->toBe(1)
        ->and(ExecuteWordPressWxrImportAction::run($path, $firstTarget)->report->pagesCreated)->toBe(0)
        ->and(ExecuteWordPressWxrImportAction::run($path, $secondTarget)->report->pagesCreated)->toBe(0);
});
