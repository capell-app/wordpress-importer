<?php

declare(strict_types=1);

use Capell\MigrationAssistant\Support\Xml\SafeXmlLoader;
use Capell\WordPressImporter\Services\WxrReader;

/**
 * Regression test for the per-item memory-exhaustion guard (finding M8).
 *
 * WxrReader streams each <item> off disk and re-materialises it via
 * SafeXmlLoader::loadString. The loader enforces a byte ceiling, but the reader
 * used to pass PHP_INT_MAX which disabled that guard, letting a single enormous
 * <item> OOM-kill the import worker. The reader must now pass the loader's real
 * default cap so an oversized item is rejected with a catchable RuntimeException
 * carrying the loader's "exceeds the safe parse limit" message — proving the cap
 * fired rather than the worker crashing.
 */
it('rejects a WXR item whose body exceeds the per-item byte cap instead of exhausting memory', function (): void {
    $exportPath = tempnam(sys_get_temp_dir(), 'capell-wxr-oversized-') . '.xml';

    // Stream the fixture to disk so the test itself never holds the oversized
    // payload in memory all at once.
    $fileHandle = fopen($exportPath, 'wb');

    fwrite($fileHandle, <<<'XML'
<?xml version="1.0" encoding="UTF-8" ?>
<rss version="2.0"
    xmlns:content="http://purl.org/rss/1.0/modules/content/"
    xmlns:wp="http://wordpress.org/export/1.2/">
    <channel>
        <title>Oversized WordPress Site</title>
        <wp:wxr_version>1.2</wp:wxr_version>
        <item>
            <title>Oversized</title>
            <wp:post_id>1</wp:post_id>
            <wp:post_type>page</wp:post_type>
            <content:encoded>
XML);

    // Spread the body across many moderate paragraph nodes so the total outer XML
    // exceeds the loader's default ceiling, while no single text node trips
    // libxml's separate "huge text node" guard. This forces the failure through
    // SafeXmlLoader's byte cap specifically.
    $oneMegabyteParagraph = '<p>' . str_repeat('A', (1024 * 1024) - 7) . '</p>';
    $paragraphsToWrite = (int) (SafeXmlLoader::DEFAULT_MAX_BYTES / (1024 * 1024)) + 2;

    for ($writtenParagraphs = 0; $writtenParagraphs < $paragraphsToWrite; $writtenParagraphs++) {
        fwrite($fileHandle, $oneMegabyteParagraph);
    }

    fwrite($fileHandle, <<<'XML'
</content:encoded>
        </item>
    </channel>
</rss>
XML);

    fclose($fileHandle);

    try {
        expect(fn (): mixed => (new WxrReader)->read($exportPath))
            ->toThrow(RuntimeException::class, 'exceeds the safe parse limit');
    } finally {
        @unlink($exportPath);
    }
});
