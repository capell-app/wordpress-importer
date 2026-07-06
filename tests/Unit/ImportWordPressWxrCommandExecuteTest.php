<?php

declare(strict_types=1);

use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\MigrationAssistant\Enums\ImportSessionStatus;
use Capell\MigrationAssistant\Models\ImportSession;
use Capell\UrlManager\Models\RedirectRule;
use Capell\WordPressImporter\Contracts\WordPressMediaHostResolver;
use Capell\WordPressImporter\Tests\Fixtures\StaticWordPressMediaHostResolver;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
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
    ]);

    $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    $page = Page::query()->withoutGlobalScopes()->where('name', 'Executable WP page')->firstOrFail();
    $redirectRule = RedirectRule::query()->where('source_url', '/legacy-executable-page')->firstOrFail();
    $session = ImportSession::query()->whereKey($output['session']['id'] ?? null)->firstOrFail();

    expect($exitCode)->toBe(0)
        ->and($output['report']['pages_created'] ?? null)->toBe(1)
        ->and(PageUrl::query()->where('pageable_id', $page->getKey())->where('url', '/executable-wp-page')->exists())->toBeTrue()
        ->and($redirectRule->target_url)->toBe('/executable-wp-page')
        ->and($session->status)->toBe(ImportSessionStatus::Completed);
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
