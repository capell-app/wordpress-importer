<?php

declare(strict_types=1);

use Capell\MigrationAssistant\Support\ImportSourceRegistry;
use Capell\WordPressImporter\Actions\BuildWordPressImportPreviewAction;
use Capell\WordPressImporter\Services\WxrReader;
use Illuminate\Support\Facades\Artisan;

it('registers the WordPress WXR reader ahead of migration-assistant XML readers', function (): void {
    $readers = resolve(ImportSourceRegistry::class)->readers();

    expect($readers[0] ?? null)
        ->toBeInstanceOf(WxrReader::class)
        ->and(resolve(ImportSourceRegistry::class)->readerFor('export.xml'))
        ->toBeInstanceOf(WxrReader::class);
});

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

    expect((new WxrReader)->supports($path))->toBeTrue()
        ->and((new WxrReader)->supportsPath($path))->toBeTrue()
        ->and((new WxrReader)->supports('xml'))->toBeTrue();
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
        ->and($result->rows[1]['contains_gutenberg_blocks'])->toBeTrue()
        ->and($result->rows[1]['shortcodes'])->toBe(['gallery']);
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
        ->and($reader)->toBeInstanceOf(WxrReader::class)
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

    $preview = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($preview['target'])->toBe('page')
        ->and($preview['creates'])->toBe(1)
        ->and($preview['rows'])->toHaveCount(1)
        ->and($preview['rows'][0]['action'])->toBe('create')
        ->and($preview['rows'][0]['attributes']['name'])->toBe('Command page')
        ->and($preview['rows'][0]['attributes']['meta']['wordpress']['source_identity'])->toBe('wordpress:42')
        ->and($preview['rows'][0]['attributes']['meta']['wordpress']['categories'])->toBe(['Updates'])
        ->and($preview['rows'][0]['attributes']['meta']['wordpress']['tags'])->toBe(['Launch'])
        ->and($preview['rows'][0]['attributes']['meta']['wordpress']['author_login'])->toBe('ben')
        ->and($preview['rows'][0]['attributes']['meta']['wordpress']['featured_media_url'])->toBe('https://example.test/command-page.jpg')
        ->and($preview['rows'][0]['attributes']['meta'])->not->toHaveKey('imported');
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
    $attributes = $importPreview->preview->rows[0]['attributes'];

    expect(BuildWordPressImportPreviewAction::fieldMapping())
        ->toHaveKey('categories', 'meta.wordpress.categories')
        ->and($importPreview->readResult->sourceType)->toBe('wordpress-wxr')
        ->and($attributes['name'])->toBe('Mapped post')
        ->and($attributes['meta']['content'])->toBe('<p>Mapped body</p>')
        ->and($attributes['meta']['status'])->toBe('draft')
        ->and($attributes['visible_from'])->toBe('2026-04-01 09:30:00')
        ->and($attributes['meta']['wordpress']['old_permalink'])->toBe('https://example.test/mapped-post/')
        ->and($attributes['meta']['wordpress']['categories'])->toBe(['Guides'])
        ->and($attributes['meta']['wordpress']['tags'])->toBe(['Featured'])
        ->and($attributes['meta']['wordpress']['author_login'])->toBe('editor')
        ->and($attributes['meta']['wordpress']['media_urls'])->toBe(['https://example.test/mapped-post.jpg'])
        ->and($attributes['meta'])->not->toHaveKey('imported');
});
