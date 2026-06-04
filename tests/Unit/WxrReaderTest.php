<?php

declare(strict_types=1);

use Capell\MigrationAssistant\Support\ImportSourceRegistry;
use Capell\WordPressImporter\Services\WxrReader;
use Illuminate\Support\Facades\Artisan;

it('registers the WordPress WXR reader with migration-assistant', function (): void {
    expect(resolve(ImportSourceRegistry::class)->readerFor('export.xml'))
        ->toBeInstanceOf(WxrReader::class);
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

it('delegates non WordPress XML imports to the generic migration-assistant XML reader', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'capell-xml-');
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

    $result = resolve(ImportSourceRegistry::class)->readerFor('catalog.xml')->read($path);

    expect($result->sourceType)->toBe('xml')
        ->and($result->rows)->toHaveCount(2)
        ->and($result->rows[0]['title'])->toBe('One');
});

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
    xmlns:content="http://purl.org/rss/1.0/modules/content/"
    xmlns:wp="http://wordpress.org/export/1.2/">
    <channel>
        <title>Command WordPress Site</title>
        <wp:wxr_version>1.2</wp:wxr_version>
        <item>
            <title>Command page</title>
            <link>https://example.test/command-page/</link>
            <content:encoded><![CDATA[<p>Command body</p>]]></content:encoded>
            <wp:post_id>42</wp:post_id>
            <wp:post_name>command-page</wp:post_name>
            <wp:post_type>page</wp:post_type>
            <wp:status>publish</wp:status>
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
        ->and($preview['rows'][0]['attributes']['meta']['imported']['source_identity'])->toBe('wordpress:42');
});
