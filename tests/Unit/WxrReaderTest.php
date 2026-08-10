<?php

declare(strict_types=1);

use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\MigrationAssistant\Actions\ExecuteImportRollbackAction;
use Capell\MigrationAssistant\Data\ExternalPageImportTargetData;
use Capell\MigrationAssistant\Enums\ImportSessionStatus;
use Capell\MigrationAssistant\Models\ImportRollbackReport;
use Capell\MigrationAssistant\Services\Import\XmlReader;
use Capell\MigrationAssistant\Support\ImportSourceRegistry;
use Capell\MigrationAssistant\Support\Xml\SafeXmlLoader;
use Capell\UrlManager\Models\RedirectRule;
use Capell\WordPressImporter\Actions\BuildWordPressImportPreviewAction;
use Capell\WordPressImporter\Actions\ExecuteWordPressWxrImportAction;
use Capell\WordPressImporter\Actions\ReadWordPressWxrAction;
use Capell\WordPressImporter\Contracts\WordPressMediaHostResolver;
use Capell\WordPressImporter\Services\WxrReader;
use Capell\WordPressImporter\Tests\Fixtures\StaticWordPressMediaHostResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint as SchemaBlueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

it('registers the WordPress WXR reader ahead of migration-assistant XML readers', function (): void {
    $readers = resolve(ImportSourceRegistry::class)->readers();

    expect($readers[0] ?? null)
        ->toBeInstanceOf(WxrReader::class)
        ->and(resolve(ImportSourceRegistry::class)->readerFor('export.xml'))
        ->toBeInstanceOf(XmlReader::class);
});

function wordpressWxrSiteId(mixed $identifier): int
{
    if (! is_int($identifier)) {
        throw new RuntimeException('Expected an integer site identifier.');
    }

    return $identifier;
}

function wordpressWxrImageBytes(): string
{
    $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);

    if (! is_string($bytes)) {
        throw new RuntimeException('Expected valid PNG fixture bytes.');
    }

    return $bytes;
}

it('supports readable WordPress WXR XML files', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'capell-wxr-support-') . '.xml';
    file_put_contents($path, <<<'XML'
<?xml version="1.0" encoding="UTF-8" ?>
<rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/">
    <channel>
        <title>Example WordPress Site</title>
        <wp:wxr_version>1.2</wp:wxr_version>
    </channel>
</rss>
XML);

    expect((new WxrReader)->supports($path))->toBeFalse()
        ->and((new WxrReader)->supportsPath($path))->toBeTrue()
        ->and((new WxrReader)->supports('xml'))->toBeFalse();
});

it('reads WordPress WXR posts and pages into migration-assistant rows', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'capell-wxr-');
    file_put_contents($path, <<<'XML'
<?xml version="1.0" encoding="UTF-8" ?>
<rss version="2.0"
    xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/"
    xmlns:content="http://purl.org/rss/1.0/modules/content/"
    xmlns:dc="http://purl.org/dc/elements/1.1/"
    xmlns:wp="http://wordpress.org/export/1.2/">
    <channel>
        <title>Example WordPress Site</title>
        <wp:wxr_version>1.2</wp:wxr_version>
        <item>
            <title>About</title>
            <link>https://example.test/about/</link>
            <content:encoded><![CDATA[<p>About body</p>]]></content:encoded>
            <excerpt:encoded><![CDATA[About excerpt]]></excerpt:encoded>
            <category domain="category" nicename="company"><![CDATA[Company]]></category>
            <category domain="post_tag" nicename="featured"><![CDATA[Featured]]></category>
            <wp:post_id>10</wp:post_id>
            <wp:post_name>about</wp:post_name>
            <wp:post_type>page</wp:post_type>
            <wp:status>publish</wp:status>
            <wp:post_date>2026-01-01 10:00:00</wp:post_date>
            <wp:post_parent>0</wp:post_parent>
            <dc:creator>ben</dc:creator>
            <wp:attachment_url>https://example.test/about-hero.jpg</wp:attachment_url>
        </item>
        <item>
            <title>Hero image</title>
            <wp:post_type>attachment</wp:post_type>
            <wp:post_parent>10</wp:post_parent>
            <wp:attachment_url>https://example.test/hero.jpg</wp:attachment_url>
        </item>
        <item>
            <title>News</title>
            <link>https://example.test/news/</link>
            <content:encoded><![CDATA[<!-- wp:paragraph --><p>News body</p><!-- /wp:paragraph -->[gallery ids="1,2"]]]></content:encoded>
            <wp:post_id>11</wp:post_id>
            <wp:post_name>news</wp:post_name>
            <wp:post_type>post</wp:post_type>
            <wp:status>draft</wp:status>
            <wp:post_parent>10</wp:post_parent>
        </item>
    </channel>
</rss>
XML);

    $result = (new WxrReader)->read($path);

    expect($result->sourceType)->toBe('wordpress-wxr')
        ->and($result->columns)->toContain('source_identity')
        ->and($result->columns)->toContain('old_permalink')
        ->and($result->columns)->toContain('featured_media_url')
        ->and($result->columns)->toContain('post_content_raw')
        ->and($result->metadata['site_title'])->toBe('Example WordPress Site')
        ->and($result->metadata['post_count'])->toBe(2)
        ->and($result->metadata['attachment_count'])->toBe(1)
        ->and($result->rows)->toHaveCount(2)
        ->and($result->rows[0]['source_identity'])->toBe('wordpress:10')
        ->and($result->rows[0]['post_title'])->toBe('About')
        ->and($result->rows[0]['old_permalink'])->toBe('https://example.test/about/')
        ->and($result->rows[0]['post_content'])->toBe('<p>About body</p>')
        ->and($result->rows[0]['author_login'])->toBe('ben')
        ->and($result->rows[0]['categories'])->toBe(['Company'])
        ->and($result->rows[0]['tags'])->toBe(['Featured'])
        ->and($result->rows[0]['attachments'])->toBe([
            ['url' => 'https://example.test/about-hero.jpg', 'title' => 'About'],
            ['url' => 'https://example.test/hero.jpg', 'title' => 'Hero image'],
        ])
        ->and($result->rows[0]['media_urls'])->toBe([
            'https://example.test/about-hero.jpg',
            'https://example.test/hero.jpg',
        ])
        ->and($result->rows[0]['featured_media_url'])->toBe('https://example.test/about-hero.jpg')
        ->and($result->rows[1]['parent_id'])->toBe('10')
        ->and($result->rows[1]['post_content'])->toBe('<p>News body</p>')
        ->and($result->rows[1]['post_content_raw'])->toBe('<!-- wp:paragraph --><p>News body</p><!-- /wp:paragraph -->[gallery ids="1,2"]')
        ->and($result->rows[1]['contains_gutenberg_blocks'])->toBeTrue()
        ->and($result->rows[1]['shortcodes'])->toBe(['gallery']);
});

it('preserves safe enclosed shortcode text without package-specific public markers', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'capell-wxr-shortcode-');
    throw_unless(is_string($path), RuntimeException::class, 'Expected shortcode WXR fixture path.');

    file_put_contents($path, <<<'XML'
<?xml version="1.0" encoding="UTF-8" ?>
<rss version="2.0"
    xmlns:content="http://purl.org/rss/1.0/modules/content/"
    xmlns:wp="http://wordpress.org/export/1.2/">
    <channel>
        <title>Shortcode Site</title>
        <wp:wxr_version>1.2</wp:wxr_version>
        <item>
            <title>Portable shortcode</title>
            <content:encoded><![CDATA[<p>Before</p>[notice tone="info"]Keep <strong>this useful text</strong>[/notice][gallery ids="1,2"]<p>After</p>]]></content:encoded>
            <wp:post_id>50</wp:post_id>
            <wp:post_name>portable-shortcode</wp:post_name>
            <wp:post_type>page</wp:post_type>
            <wp:status>publish</wp:status>
        </item>
    </channel>
</rss>
XML);

    try {
        $result = (new WxrReader)->read($path);

        expect($result->rows[0]['post_content'])
            ->toBe('<p>Before</p>Keep this useful text<p>After</p>')
            ->not->toContain('capell-wordpress', 'data-shortcode', 'data-attributes')
            ->and($result->rows[0]['post_content_raw'])->toContain('[notice tone="info"]')
            ->and($result->rows[0]['shortcodes'])->toBe(['notice', 'gallery']);
    } finally {
        unlink($path);
    }
});

it('fails closed when the streamed attachment index exceeds its configured bound', function (): void {
    config()->set('wordpress-importer.spool.max_indexed_attachments', 1);
    $path = tempnam(sys_get_temp_dir(), 'capell-wxr-attachments-');
    throw_unless(is_string($path), RuntimeException::class, 'Expected attachment WXR fixture path.');

    file_put_contents($path, <<<'XML'
<?xml version="1.0" encoding="UTF-8" ?>
<rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/">
    <channel>
        <title>Attachment Site</title>
        <wp:wxr_version>1.2</wp:wxr_version>
        <item><title>One</title><wp:post_type>attachment</wp:post_type><wp:post_parent>50</wp:post_parent><wp:attachment_url>https://example.test/one.jpg</wp:attachment_url></item>
        <item><title>Two</title><wp:post_type>attachment</wp:post_type><wp:post_parent>50</wp:post_parent><wp:attachment_url>https://example.test/two.jpg</wp:attachment_url></item>
        <item><title>Page</title><wp:post_id>50</wp:post_id><wp:post_type>page</wp:post_type></item>
    </channel>
</rss>
XML);

    try {
        expect(fn () => (new WxrReader)->read($path))
            ->toThrow(RuntimeException::class, 'attachment index limit');
    } finally {
        unlink($path);
    }
});

it('reads WordPress WXR exports through an action boundary', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'capell-wxr-read-action-');
    file_put_contents($path, <<<'XML'
<?xml version="1.0" encoding="UTF-8" ?>
<rss version="2.0"
    xmlns:content="http://purl.org/rss/1.0/modules/content/"
    xmlns:wp="http://wordpress.org/export/1.2/">
    <channel>
        <title>Action WordPress Site</title>
        <wp:wxr_version>1.2</wp:wxr_version>
        <item>
            <title>Action page</title>
            <content:encoded><![CDATA[<p>Action body</p>]]></content:encoded>
            <wp:post_id>10</wp:post_id>
            <wp:post_name>action-page</wp:post_name>
            <wp:post_type>page</wp:post_type>
            <wp:status>publish</wp:status>
        </item>
    </channel>
</rss>
XML);

    $result = ReadWordPressWxrAction::run($path);

    expect($result->path)->toBe(realpath($path))
        ->and($result->readResult->sourceType)->toBe('wordpress-wxr')
        ->and($result->readResult->rows)->toHaveCount(1);
});

it('streams WordPress WXR exports larger than the migration assistant DOM safety cap', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'capell-wxr-large-');
    throw_unless(is_string($path), RuntimeException::class, 'Expected large WXR fixture path.');

    try {
        $handle = fopen($path, 'wb');
        throw_unless(is_resource($handle), RuntimeException::class, 'Expected large WXR fixture handle.');

        fwrite($handle, <<<'XML'
<?xml version="1.0" encoding="UTF-8" ?>
<rss version="2.0"
    xmlns:content="http://purl.org/rss/1.0/modules/content/"
    xmlns:wp="http://wordpress.org/export/1.2/">
    <channel>
        <title>Large WordPress Site</title>
        <wp:wxr_version>1.2</wp:wxr_version>
XML);

        $commentChunk = '<!-- ' . str_repeat('x', 1024 * 1024) . ' -->';

        for ($chunk = 0; $chunk < 52; $chunk++) {
            fwrite($handle, $commentChunk);
        }

        fwrite($handle, <<<'XML'
        <item>
            <title>Large export page</title>
            <content:encoded><![CDATA[<p>Large body</p>]]></content:encoded>
            <wp:post_id>501</wp:post_id>
            <wp:post_name>large-export-page</wp:post_name>
            <wp:post_type>page</wp:post_type>
            <wp:status>publish</wp:status>
            <wp:post_parent>0</wp:post_parent>
        </item>
    </channel>
</rss>
XML);
        fclose($handle);

        expect(filesize($path))->toBeGreaterThan(SafeXmlLoader::DEFAULT_MAX_BYTES);

        $result = (new WxrReader)->read($path);

        expect($result->metadata['site_title'])->toBe('Large WordPress Site')
            ->and($result->metadata['post_count'])->toBe(1)
            ->and($result->rows[0]['post_title'])->toBe('Large export page');
    } finally {
        if (file_exists($path)) {
            unlink($path);
        }
    }
});

it('does not claim generic XML paths during direct path-aware probes', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'capell-xml-') . '.xml';
    file_put_contents($path, <<<'XML'
<?xml version="1.0" encoding="UTF-8" ?>
<catalog>
    <book>
        <title>One</title>
    </book>
    <book>
        <title>Two</title>
    </book>
</catalog>
XML);

    $reader = resolve(ImportSourceRegistry::class)->readerFor($path);
    $result = $reader->read($path);

    expect((new WxrReader)->supports($path))->toBeFalse()
        ->and((new WxrReader)->supportsPath($path))->toBeFalse()
        ->and($reader)->toBeInstanceOf(XmlReader::class)
        ->and($result->sourceType)->toBe('xml')
        ->and($result->rows)->toHaveCount(2)
        ->and($result->rows[0]['title'])->toBe('One');
});

it('rejects generic XML from the headless WordPress preview action', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'capell-generic-xml-');
    file_put_contents($path, <<<'XML'
<?xml version="1.0" encoding="UTF-8" ?>
<catalog>
    <book>
        <title>One</title>
    </book>
</catalog>
XML);

    BuildWordPressImportPreviewAction::run($path);
})->throws(RuntimeException::class, 'is not a readable WXR XML export');

it('rejects WordPress WXR imports with doctype declarations', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'capell-wxr-');
    file_put_contents($path, <<<'XML'
<?xml version="1.0" encoding="UTF-8" ?>
<!DOCTYPE rss [
    <!ENTITY laugh "laugh">
]>
<rss version="2.0">
    <channel>
        <title>&laugh;</title>
        <item><title>About</title><wp:post_type xmlns:wp="http://wordpress.org/export/1.2/">page</wp:post_type></item>
    </channel>
</rss>
XML);

    (new WxrReader)->read($path);
})->throws(RuntimeException::class, 'DOCTYPE');

it('builds a headless migration assistant preview from the console command', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'capell-wxr-');
    file_put_contents($path, <<<'XML'
<?xml version="1.0" encoding="UTF-8" ?>
<rss version="2.0"
    xmlns:dc="http://purl.org/dc/elements/1.1/"
    xmlns:content="http://purl.org/rss/1.0/modules/content/"
    xmlns:wp="http://wordpress.org/export/1.2/">
    <channel>
        <title>Command WordPress Site</title>
        <wp:wxr_version>1.2</wp:wxr_version>
        <item>
            <title>Command page</title>
            <link>https://example.test/command-page/</link>
            <content:encoded><![CDATA[<p>Command body</p>]]></content:encoded>
            <category domain="category"><![CDATA[Updates]]></category>
            <category domain="post_tag"><![CDATA[Launch]]></category>
            <wp:post_id>42</wp:post_id>
            <wp:post_name>command-page</wp:post_name>
            <wp:post_type>page</wp:post_type>
            <wp:status>publish</wp:status>
            <dc:creator>ben</dc:creator>
            <wp:attachment_url>https://example.test/command-page.jpg</wp:attachment_url>
        </item>
    </channel>
</rss>
XML);

    $exitCode = Artisan::call('wordpress-importer:import', [
        'path' => $path,
        '--json' => true,
    ]);

    $preview = capell_json_array(Artisan::output());
    $rows = wxrArrayValue($preview, 'rows');
    $row = wxrArrayValue($rows, 0);
    $attributes = wxrArrayValue($row, 'attributes');
    $meta = wxrArrayValue($attributes, 'meta');
    $wordpressMeta = wxrArrayValue($meta, 'wordpress');

    expect($exitCode)->toBe(0)
        ->and($preview['target'])->toBe('page')
        ->and($preview['creates'])->toBe(1)
        ->and($rows)->toHaveCount(1)
        ->and($row['action'] ?? null)->toBe('create')
        ->and($attributes['name'] ?? null)->toBe('Command page')
        ->and($wordpressMeta['source_identity'] ?? null)->toBe('wordpress:42')
        ->and($wordpressMeta['categories'] ?? null)->toBe(['Updates'])
        ->and($wordpressMeta['tags'] ?? null)->toBe(['Launch'])
        ->and($wordpressMeta['author_login'] ?? null)->toBe('ben')
        ->and($wordpressMeta['featured_media_url'] ?? null)->toBe('https://example.test/command-page.jpg')
        ->and($meta)->not->toHaveKey('imported');
});

it('maps WordPress WXR fields into explicit preview metadata', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'capell-wxr-preview-');
    file_put_contents($path, <<<'XML'
<?xml version="1.0" encoding="UTF-8" ?>
<rss version="2.0"
    xmlns:dc="http://purl.org/dc/elements/1.1/"
    xmlns:content="http://purl.org/rss/1.0/modules/content/"
    xmlns:wp="http://wordpress.org/export/1.2/">
    <channel>
        <title>Mapped WordPress Site</title>
        <wp:wxr_version>1.2</wp:wxr_version>
        <item>
            <title>Mapped post</title>
            <link>https://example.test/mapped-post/</link>
            <content:encoded><![CDATA[<p>Mapped body</p>]]></content:encoded>
            <category domain="category"><![CDATA[Guides]]></category>
            <category domain="post_tag"><![CDATA[Featured]]></category>
            <wp:post_id>77</wp:post_id>
            <wp:post_name>mapped-post</wp:post_name>
            <wp:post_type>post</wp:post_type>
            <wp:status>draft</wp:status>
            <wp:post_date>2026-04-01 09:30:00</wp:post_date>
            <dc:creator>editor</dc:creator>
            <wp:attachment_url>https://example.test/mapped-post.jpg</wp:attachment_url>
        </item>
    </channel>
</rss>
XML);

    $importPreview = BuildWordPressImportPreviewAction::run($path);
    $row = wxrArrayValue($importPreview->preview->rows, 0);
    $attributes = wxrArrayValue($row, 'attributes');
    $meta = wxrArrayValue($attributes, 'meta');
    $wordpressMeta = wxrArrayValue($meta, 'wordpress');

    expect(BuildWordPressImportPreviewAction::fieldMapping())
        ->toHaveKey('categories', 'meta.wordpress.categories')
        ->toHaveKey('post_content_raw', 'meta.wordpress.raw_content')
        ->and($importPreview->readResult->sourceType)->toBe('wordpress-wxr')
        ->and($attributes['name'] ?? null)->toBe('Mapped post')
        ->and($meta['content'] ?? null)->toBe('<p>Mapped body</p>')
        ->and($meta['status'] ?? null)->toBe('draft')
        ->and($attributes['visible_from'] ?? null)->toBe('2026-04-01 09:30:00')
        ->and($wordpressMeta['old_permalink'] ?? null)->toBe('https://example.test/mapped-post/')
        ->and($wordpressMeta['categories'] ?? null)->toBe(['Guides'])
        ->and($wordpressMeta['tags'] ?? null)->toBe(['Featured'])
        ->and($wordpressMeta['author_login'] ?? null)->toBe('editor')
        ->and($wordpressMeta['media_urls'] ?? null)->toBe(['https://example.test/mapped-post.jpg'])
        ->and($wordpressMeta['raw_content'] ?? null)->toBe('<p>Mapped body</p>')
        ->and($meta)->not->toHaveKey('imported');
});

it('executes a WordPress WXR preview through migration-assistant into a page session', function (): void {
    Notification::fake();
    $this->actingAsAdmin();
    ensureWordPressImporterUrlManagerRedirectRulesTable();
    Storage::fake('public');
    Http::fake([
        'https://example.test/uploads/executable.jpg' => Http::response(wordpressWxrImageBytes(), 200, ['Content-Type' => 'image/png']),
    ]);
    app()->instance(WordPressMediaHostResolver::class, new StaticWordPressMediaHostResolver([
        'example.test' => ['93.184.216.34'],
    ]));

    $layout = Layout::factory()->create();
    $type = Blueprint::factory()->page()->create();
    $site = Site::factory()->create();
    $path = tempnam(sys_get_temp_dir(), 'capell-wxr-execute-');
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
            <category domain="category"><![CDATA[Migration]]></category>
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
        <item>
            <title>Executable WP child page</title>
            <link>https://example.test/executable-wp-child-page/</link>
            <content:encoded><![CDATA[<p>Executable child body</p>]]></content:encoded>
            <wp:post_id>142</wp:post_id>
            <wp:post_name>executable-wp-child-page</wp:post_name>
            <wp:post_type>page</wp:post_type>
            <wp:status>publish</wp:status>
            <wp:post_parent>141</wp:post_parent>
        </item>
    </channel>
</rss>
XML);

    // URL Manager intentionally rejects redirect writes inside a transaction.
    // RefreshDatabase holds the test in one, so suspend it around this import.
    DB::commit();

    try {
        $result = ExecuteWordPressWxrImportAction::run(
            $path,
            new ExternalPageImportTargetData(
                siteId: (int) $site->getKey(),
                layoutId: (int) $layout->getKey(),
                blueprintId: (int) $type->getKey(),
                languageId: (int) $site->language_id,
            ),
        );
    } finally {
        DB::beginTransaction();
    }

    $parentPage = Page::query()
        ->withoutGlobalScopes()
        ->whereKey($result->report->createdPageIds[0])
        ->firstOrFail();
    $childPage = Page::query()
        ->withoutGlobalScopes()
        ->where('name', 'Executable WP child page')
        ->firstOrFail();
    $parentMeta = $parentPage->getAttribute('meta');

    throw_unless(is_array($parentMeta), RuntimeException::class, 'Expected executable parent page meta array.');

    $parentWordPressMeta = wxrArrayValue($parentMeta, 'wordpress');
    $redirectRule = RedirectRule::query()
        ->where('source_url', '/legacy-executable-page')
        ->firstOrFail();
    $rollbackReport = ImportRollbackReport::query()
        ->where('import_session_id', $result->session->getKey())
        ->firstOrFail();
    $session = $result->session->refresh();
    $redirectReport = wxrArrayValue($session->result_summary ?? [], 'wordpress_permalink_redirects');
    $rollbackRedirectReport = wxrArrayValue($rollbackReport->summary ?? [], 'wordpress_permalink_redirects');
    $rollbackCreatedModels = is_array($rollbackReport->created_models) ? $rollbackReport->created_models : [];
    $importedMedia = wxrArrayValue($parentWordPressMeta, 'imported_media');
    $firstImportedMedia = wxrArrayValue($importedMedia, 0);
    $redirectRows = wxrArrayValue($redirectReport, 'redirects');
    $firstRedirect = wxrArrayValue($redirectRows, 0);

    expect($result->report->errors)->toBe([])
        ->and($result->session->status)->toBe(ImportSessionStatus::Completed)
        ->and($result->report->pagesCreated)->toBe(2)
        ->and($result->report->pageUrlsCreated)->toBe(2)
        ->and($parentPage->name)->toBe('Executable WP page')
        ->and($parentMeta['content'] ?? null)->not->toContain('https://example.test/uploads/executable.jpg')
        ->and($parentMeta['content'] ?? null)->toContain('/storage/')
        ->and($parentWordPressMeta['source_identity'] ?? null)->toBe('wordpress:141')
        ->and($parentWordPressMeta['categories'] ?? null)->toBe(['Migration'])
        ->and($importedMedia)->toHaveCount(1)
        ->and($firstImportedMedia['source_url'] ?? null)->toBe('https://example.test/uploads/executable.jpg')
        ->and($parentPage->getMedia('wordpress-import'))->toHaveCount(1)
        ->and(wxrIntValue($childPage->getAttribute('parent_id')))->toBe(wxrIntValue($parentPage->getKey()))
        ->and(PageUrl::query()->where('url', '/executable-wp-page')->exists())->toBeTrue()
        ->and(PageUrl::query()->where('url', '/executable-wp-child-page')->exists())->toBeTrue()
        ->and($redirectRule->target_url)->toBe('/executable-wp-page')
        ->and($redirectReport['created'] ?? null)->toBe(1)
        ->and($redirectReport['skipped'] ?? null)->toBe(1)
        ->and($firstRedirect['old_url'] ?? null)->toBe('https://example.test/legacy-executable-page/')
        ->and($firstRedirect['redirect_rule_id'] ?? null)->toBe($redirectRule->getKey())
        ->and($rollbackRedirectReport['created'] ?? null)->toBe(1)
        ->and($rollbackCreatedModels)->not->toContain([
            'class' => RedirectRule::class,
            'id' => $redirectRule->getKey(),
        ]);

    ExecuteImportRollbackAction::run($rollbackReport->refresh());

    expect(RedirectRule::query()->whereKey($redirectRule->getKey())->exists())->toBeTrue();
});

it('rejects a WordPress WXR import target outside the actor site scope', function (): void {
    $authorizedSite = Site::factory()->create();
    $otherSite = Site::factory()->create();
    $otherLayout = Layout::factory()->site($otherSite)->create();
    $type = Blueprint::factory()->page()->create();
    $actor = $this->actingAsUser()->authenticatedUser();
    $actor->setAttribute('assignedSiteIds', collect([wordpressWxrSiteId($authorizedSite->getKey())]));
    $path = tempnam(sys_get_temp_dir(), 'capell-wxr-unauthorized-target-');

    file_put_contents($path, <<<'XML'
<?xml version="1.0" encoding="UTF-8" ?>
<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:wp="http://wordpress.org/export/1.2/">
    <channel>
        <title>Unauthorized WordPress Site</title>
        <wp:wxr_version>1.2</wp:wxr_version>
        <item>
            <title>Unauthorized page</title>
            <content:encoded><![CDATA[<p>Blocked.</p>]]></content:encoded>
            <wp:post_id>901</wp:post_id>
            <wp:post_name>unauthorized-page</wp:post_name>
            <wp:post_type>page</wp:post_type>
            <wp:status>publish</wp:status>
        </item>
    </channel>
</rss>
XML);

    expect(fn (): mixed => ExecuteWordPressWxrImportAction::run(
        $path,
        new ExternalPageImportTargetData(
            siteId: (int) $otherSite->getKey(),
            layoutId: (int) $otherLayout->getKey(),
            blueprintId: (int) $type->getKey(),
            languageId: (int) $otherSite->language_id,
        ),
    ))->toThrow(AuthorizationException::class, 'not authorized');

    expect(Page::query()->withoutGlobalScopes()->where('site_id', $otherSite->getKey())->exists())->toBeFalse();
});

/**
 * @param  array<array-key, mixed>  $values
 * @return array<array-key, mixed>
 */
function wxrArrayValue(array $values, int|string $key): array
{
    $value = $values[$key] ?? null;

    throw_unless(is_array($value), RuntimeException::class, sprintf('Expected WXR value [%s] to be an array.', (string) $key));

    return $value;
}

function wxrIntValue(mixed $value): int
{
    return is_numeric($value) ? (int) $value : 0;
}

function ensureWordPressImporterUrlManagerRedirectRulesTable(): void
{
    if (Schema::hasTable('url_manager_redirect_rules')) {
        return;
    }

    Schema::create('url_manager_redirect_rules', function (SchemaBlueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('site_id')->nullable();
        $table->unsignedBigInteger('language_id')->nullable();
        $table->string('source_url', 2048);
        $table->string('source_hash', 64);
        $table->string('target_url', 2048);
        $table->string('target_hash', 64);
        $table->unsignedSmallInteger('status_code')->default(301);
        $table->string('match_type')->default('exact');
        $table->string('status')->default('active');
        $table->integer('priority')->default(0);
        $table->boolean('preserve_query')->default(true);
        $table->unsignedInteger('hit_count')->default(0);
        $table->timestamp('last_hit_at')->nullable();
        $table->text('notes')->nullable();
        $table->unsignedBigInteger('created_by_user_id')->nullable();
        $table->timestamps();
    });

    (require dirname(__DIR__, 3) . '/url-manager/database/migrations/2026_07_30_000001_add_normalized_scope_keys_to_url_manager_redirect_rules_table.php')->up();
}
