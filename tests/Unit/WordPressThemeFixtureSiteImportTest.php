<?php

declare(strict_types=1);

use Capell\Core\Contracts\Security\ResolvesHostAddresses;
use Capell\Core\Enums\MediaCollectionEnum;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Taxonomy;
use Capell\Core\Models\Term;
use Capell\MigrationAssistant\Data\ExternalPageImportTargetData;
use Capell\MigrationAssistant\Enums\ImportSessionStatus;
use Capell\Tests\Fixtures\Models\User;
use Capell\WordPressImporter\Actions\ExecuteWordPressWxrImportAction;
use Capell\WordPressImporter\Tests\Fixtures\StaticWordPressMediaHostResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

const THEME_FIXTURE_SITE_EXPORT = __DIR__ . '/../../workbench/fixtures/theme-fixture-site.xml';

function themeFixtureSiteImageBytes(): string
{
    $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);

    throw_unless(is_string($bytes), RuntimeException::class, 'Expected valid PNG fixture bytes.');

    return $bytes;
}

beforeEach(function (): void {
    Notification::fake();
    Storage::fake('public');
    $this->actingAsAdmin();
    $this->site = Site::factory()->create();
    $this->layout = Layout::factory()->create();
    $this->type = Blueprint::factory()->page()->create();
    Http::fake(['https://fixture-site.example.test/wp-content/uploads/*' => Http::response(themeFixtureSiteImageBytes(), 200, ['Content-Type' => 'image/png'])]);
    app()->instance(ResolvesHostAddresses::class, new StaticWordPressMediaHostResolver(['fixture-site.example.test' => ['93.184.216.34']]));
});

it('imports the theme fixture site with every post, page, author and term, and no WordPress markup left in the content', function (): void {
    $amara = User::factory()->create(['email' => 'amara@fixture-site.example.test']);
    $priya = User::factory()->create(['email' => 'priya@fixture-site.example.test']);
    $usersBefore = User::query()->count();

    // Redirects rejects writes inside a transaction, and RefreshDatabase holds the test in one.
    DB::commit();

    try {
        $session = ExecuteWordPressWxrImportAction::run(
            THEME_FIXTURE_SITE_EXPORT,
            new ExternalPageImportTargetData(
                siteId: $this->site->id,
                layoutId: $this->layout->id,
                blueprintId: $this->type->id,
                languageId: (int) $this->site->language_id,
            ),
        )->session->refresh();
    } finally {
        DB::beginTransaction();
    }

    $summary = is_array($session->result_summary) ? $session->result_summary : [];
    $pages = Page::query()->withoutGlobalScopes()->where('site_id', $this->site->getKey())->get();
    $contents = $pages->map(static fn (Page $page): string => is_string($page->meta['content'] ?? null) ? $page->meta['content'] : '');

    expect($session->status)->toBe(ImportSessionStatus::Completed)
        ->and($pages)->toHaveCount(35)
        ->and($pages->pluck('name')->all())->toContain('Rebuilding the harbour workshop', 'Ten years at the same address', 'About us', 'Privacy notice')
        ->and(Taxonomy::query()->where('site_id', $this->site->getKey())->pluck('key')->sort()->values()->all())->toBe(['category', 'tag'])
        ->and(Term::query()->count())->toBe(12)
        ->and(User::query()->count())->toBe($usersBefore)
        ->and($pages->firstWhere('name', 'Rebuilding the harbour workshop')?->getAttributes()['created_by'] ?? null)->toBe($amara->id)
        ->and($pages->firstWhere('name', 'Measuring the footprint of a print run')?->getAttributes()['created_by'] ?? null)->toBe($priya->id)
        ->and(data_get($summary, 'wordpress_taxonomies_and_authors.unmatched_authors'))->toBe(['tomas'])
        ->and(data_get($summary, 'errors'))->toBe([])
        ->and(data_get($summary, 'wordpress_media.imported_count'))->toBe(54)
        ->and(data_get($summary, 'wordpress_media.failed_urls'))->toBe([]);

    $contents->each(static function (string $content): void {
        expect($content)->not->toBeEmpty()
            ->not->toContain('<!--')
            ->not->toContain('wp-block')
            ->not->toContain('wp:')
            ->not->toContain('<script')
            ->not->toContain('[gallery')
            ->not->toContain('https://fixture-site.example.test/wp-content/uploads/');
    });

    $pages->each(static function (Page $page): void {
        $image = $page->image()->first();
        $thumbnailUrl = data_get($page->meta, 'wordpress.thumbnail_media_url');

        if (! is_string($thumbnailUrl)) {
            expect($image)->toBeNull();

            return;
        }

        expect($image?->collection_name)->toBe(MediaCollectionEnum::Image->value)
            ->and($image?->file_name)->toBe(basename($thumbnailUrl));
    });

    expect($pages->filter(static fn (Page $page): bool => $page->image()->exists())->count())->toBe(30);

    expect($contents->filter(static fn (string $content): bool => str_contains($content, '<table>'))->count())->toBe(10)
        ->and($contents->filter(static fn (string $content): bool => str_contains($content, '<blockquote>'))->count())->toBeGreaterThan(0);
});
