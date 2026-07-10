<?php

declare(strict_types=1);

use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\MigrationAssistant\Actions\RetryImportSessionAction;
use Capell\MigrationAssistant\Data\ExternalPageImportTargetData;
use Capell\MigrationAssistant\Enums\ImportSessionStatus;
use Capell\MigrationAssistant\Jobs\ExecuteImportPlanJob;
use Capell\MigrationAssistant\Models\ImportSession;
use Capell\MigrationAssistant\Services\Import\MediaIngestService;
use Capell\MigrationAssistant\Services\Import\PackageReader;
use Capell\MigrationAssistant\Services\Import\PageImportService;
use Capell\MigrationAssistant\Services\Import\SiteImportService;
use Capell\MigrationAssistant\Support\ImportSessionExecutorRegistry;
use Capell\Tests\Fixtures\Models\User;
use Capell\UrlManager\Models\RedirectRule;
use Capell\WordPressImporter\Actions\ExecuteWordPressWxrImportAction;
use Capell\WordPressImporter\Contracts\WordPressMediaHostResolver;
use Capell\WordPressImporter\Tests\Fixtures\StaticWordPressMediaHostResolver;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    Notification::fake();
    Role::findOrCreate('super_admin', 'web');
    $this->actingAs(User::factory()->create()->assignRole('super_admin'));

    if (! Schema::hasTable('url_manager_redirect_rules')) {
        (require dirname(__DIR__, 3) . '/url-manager/database/migrations/2026_05_31_000001_create_url_manager_redirect_rules_table.php')->up();
    }

    Storage::fake('public');
    Http::fake([
        'https://example.test/uploads/executable.jpg' => Http::response('fake image bytes', 200, ['Content-Type' => 'image/jpeg']),
    ]);
    app()->instance(WordPressMediaHostResolver::class, new StaticWordPressMediaHostResolver([
        'example.test' => ['93.184.216.34'],
    ]));
});

it('executes a WordPress WXR import through the console command into pages and redirects', function (): void {
    $actor = auth()->user();
    throw_unless($actor instanceof User, RuntimeException::class, 'Expected an authenticated admin user.');
    $layout = Layout::factory()->create();
    $type = Blueprint::factory()->page()->create();
    $site = Site::factory()->create();

    $path = tempnam(sys_get_temp_dir(), 'capell-wxr-command-execute-');
    file_put_contents($path, <<<'XML'
<?xml version="1.0" encoding="UTF-8" ?>
<rss version="2.0"
    xmlns:dc="http://purl.org/dc/elements/1.1/"
    xmlns:content="http://purl.org/rss/1.0/modules/content/"
    xmlns:wp="http://wordpress.org/export/1.2/">
    <channel>
        <title>Executable WordPress Site</title>
        <wp:wxr_version>1.2</wp:wxr_version>
        <item>
            <title>Executable WP page</title>
            <link>https://example.test/legacy-executable-page/</link>
            <content:encoded><![CDATA[<p>Executable body <img src="https://example.test/uploads/executable.jpg" alt="Imported"></p>]]></content:encoded>
            <wp:post_id>141</wp:post_id>
            <wp:post_name>executable-wp-page</wp:post_name>
            <wp:post_type>page</wp:post_type>
            <wp:status>publish</wp:status>
            <wp:post_date>2026-05-01 12:00:00</wp:post_date>
            <dc:creator>ben</dc:creator>
        </item>
        <item>
            <title>Executable image</title>
            <wp:post_id>143</wp:post_id>
            <wp:post_type>attachment</wp:post_type>
            <wp:post_parent>141</wp:post_parent>
            <wp:attachment_url>https://example.test/uploads/executable.jpg</wp:attachment_url>
        </item>
    </channel>
</rss>
XML);

    $exitCode = Artisan::call('wordpress-importer:import', [
        'path' => $path,
        '--execute' => true,
        '--json' => true,
        '--site-id' => $site->getKey(),
        '--layout-id' => $layout->getKey(),
        '--type-id' => $type->getKey(),
        '--actor-id' => $actor->getKey(),
    ]);

    $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    $page = Page::query()->withoutGlobalScopes()->where('name', 'Executable WP page')->firstOrFail();
    $redirectRule = RedirectRule::query()->where('source_url', '/legacy-executable-page')->firstOrFail();
    $session = ImportSession::query()->whereKey($output['session']['id'] ?? null)->firstOrFail();

    expect($exitCode)->toBe(0)
        ->and($output['report']['pages_created'] ?? null)->toBe(1)
        ->and(PageUrl::query()->where('pageable_id', $page->getKey())->where('url', '/executable-wp-page')->exists())->toBeTrue()
        ->and($redirectRule->target_url)->toBe('/executable-wp-page')
        ->and($session->status)->toBe(ImportSessionStatus::Completed)
        ->and($session->source_environment)->toBe('wordpress-wxr')
        ->and($session->result_summary['wordpress_checkpoint']['next_chunk_index'] ?? null)
        ->toBe($session->result_summary['wordpress_checkpoint']['total_chunks'] ?? null)
        ->and($session->result_summary['wordpress_media']['imported_count'] ?? null)->toBe(1)
        ->and($page->refresh()->meta['content'] ?? null)->not->toContain('https://example.test/uploads/executable.jpg');
});

it('requires target ids when executing', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'capell-wxr-command-execute-missing-');
    file_put_contents($path, <<<'XML'
<?xml version="1.0" encoding="UTF-8" ?>
<rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/">
    <channel>
        <title>Executable WordPress Site</title>
        <wp:wxr_version>1.2</wp:wxr_version>
    </channel>
</rss>
XML);

    Artisan::call('wordpress-importer:import', [
        'path' => $path,
        '--execute' => true,
    ]);
})->throws(RuntimeException::class);

it('imports parent relationships across spool chunk boundaries', function (): void {
    config()->set('wordpress-importer.spool.chunk_rows', 1);
    $layout = Layout::factory()->create();
    $type = Blueprint::factory()->page()->create();
    $site = Site::factory()->create();
    $path = tempnam(sys_get_temp_dir(), 'capell-wxr-parent-chunks-');
    throw_unless(is_string($path), RuntimeException::class, 'Expected a temporary WXR path.');

    file_put_contents($path, <<<'XML'
<?xml version="1.0" encoding="UTF-8" ?>
<rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/">
    <channel>
        <title>Chunk Parent Site</title>
        <wp:wxr_version>1.2</wp:wxr_version>
        <item>
            <title>Parent page</title><wp:post_id>201</wp:post_id><wp:post_name>parent</wp:post_name>
            <wp:post_type>page</wp:post_type><wp:post_parent>0</wp:post_parent>
        </item>
        <item>
            <title>Child page</title><wp:post_id>202</wp:post_id><wp:post_name>child</wp:post_name>
            <wp:post_type>page</wp:post_type><wp:post_parent>201</wp:post_parent>
        </item>
    </channel>
</rss>
XML);

    $result = ExecuteWordPressWxrImportAction::run(
        $path,
        wordpressImportTarget($site, $layout, $type),
    );
    $parent = Page::query()->withoutGlobalScopes()->where('name', 'Parent page')->firstOrFail();
    $child = Page::query()->withoutGlobalScopes()->where('name', 'Child page')->firstOrFail();

    expect($result->session->status)->toBe(ImportSessionStatus::Completed)
        ->and($result->session->result_summary['wordpress_checkpoint']['total_chunks'] ?? null)->toBe(2)
        ->and((int) $child->parent_id)->toBe((int) $parent->getKey());
});

it('resumes failed media downloads from the persisted session checkpoint', function (): void {
    $attempt = 0;
    Http::fake(function () use (&$attempt): PromiseInterface {
        $attempt++;

        return $attempt === 1
            ? Http::response('', 503)
            : Http::response('retry image bytes', 200, ['Content-Type' => 'image/jpeg']);
    });
    $layout = Layout::factory()->create();
    $type = Blueprint::factory()->page()->create();
    $site = Site::factory()->create();
    $path = tempnam(sys_get_temp_dir(), 'capell-wxr-media-resume-');
    throw_unless(is_string($path), RuntimeException::class, 'Expected a temporary WXR path.');

    file_put_contents($path, <<<'XML'
<?xml version="1.0" encoding="UTF-8" ?>
<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:wp="http://wordpress.org/export/1.2/">
    <channel>
        <title>Resume Media Site</title>
        <wp:wxr_version>1.2</wp:wxr_version>
        <item>
            <title>Resume page</title>
            <content:encoded><![CDATA[<p><img src="https://example.test/uploads/resume.jpg"></p>]]></content:encoded>
            <wp:post_id>301</wp:post_id><wp:post_name>resume</wp:post_name><wp:post_type>page</wp:post_type>
            <wp:attachment_url>https://example.test/uploads/resume.jpg</wp:attachment_url>
        </item>
    </channel>
</rss>
XML);

    try {
        ExecuteWordPressWxrImportAction::run(
            $path,
            wordpressImportTarget($site, $layout, $type),
        );
    } catch (RuntimeException $runtimeException) {
        expect($runtimeException->getMessage())->toContain('pending download');
    }

    $session = ImportSession::query()->where('source_environment', 'wordpress-wxr')->latest('id')->firstOrFail();

    expect($session->status)->toBe(ImportSessionStatus::Failed)
        ->and($session->result_summary['wordpress_checkpoint']['next_chunk_index'] ?? null)
        ->toBe($session->result_summary['wordpress_checkpoint']['total_chunks'] ?? null)
        ->and(RetryImportSessionAction::canRetry($session))->toBeTrue();

    Queue::fake();
    RetryImportSessionAction::run($session);
    $queuedJob = null;

    Queue::assertPushed(ExecuteImportPlanJob::class, function (ExecuteImportPlanJob $job) use (&$queuedJob, $session): bool {
        $queuedJob = $job;

        return $job->importSessionId === $session->getKey();
    });

    expect($queuedJob)->toBeInstanceOf(ExecuteImportPlanJob::class);

    $queuedJob->handle(
        resolve(PackageReader::class),
        resolve(PageImportService::class),
        resolve(MediaIngestService::class),
        resolve(SiteImportService::class),
        resolve(ImportSessionExecutorRegistry::class),
    );

    expect($session->refresh()->status)->toBe(ImportSessionStatus::Completed)
        ->and($session->result_summary['wordpress_media']['imported_count'] ?? null)->toBe(1)
        ->and(Page::query()->withoutGlobalScopes()->where('name', 'Resume page')->count())->toBe(1);
});

function wordpressImportTarget(Site $site, Layout $layout, Blueprint $blueprint): ExternalPageImportTargetData
{
    return new ExternalPageImportTargetData(
        siteId: (int) $site->getKey(),
        layoutId: (int) $layout->getKey(),
        blueprintId: (int) $blueprint->getKey(),
        languageId: (int) $site->language_id,
    );
}
