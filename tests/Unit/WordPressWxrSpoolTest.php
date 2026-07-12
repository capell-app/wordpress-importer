<?php

declare(strict_types=1);

use Capell\WordPressImporter\Actions\SpoolWordPressWxrAction;
use Illuminate\Support\Facades\Storage;

it('spools WXR rows into bounded storage chunks with a resumable manifest', function (): void {
    Storage::fake('local');
    config()->set('wordpress-importer.spool.disk', 'local');
    config()->set('wordpress-importer.spool.chunk_rows', 1);
    $path = tempnam(sys_get_temp_dir(), 'capell-wxr-spool-');
    throw_unless(is_string($path), RuntimeException::class, 'Expected a temporary WXR path.');

    file_put_contents($path, <<<'XML'
<?xml version="1.0" encoding="UTF-8" ?>
<rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/">
    <channel>
        <title>Spool Site</title>
        <wp:wxr_version>1.2</wp:wxr_version>
        <item><title>One</title><wp:post_id>1</wp:post_id><wp:post_type>page</wp:post_type></item>
        <item><title>Two</title><wp:post_id>2</wp:post_id><wp:post_type>page</wp:post_type></item>
        <item><title>Three</title><wp:post_id>3</wp:post_id><wp:post_type>post</wp:post_type></item>
    </channel>
</rss>
XML);

    $spool = SpoolWordPressWxrAction::run($path);

    expect($spool->rowCount)->toBe(3)
        ->and($spool->chunkPaths)->toHaveCount(3)
        ->and($spool->metadata['site_title'] ?? null)->toBe('Spool Site');

    Storage::disk('local')->assertExists($spool->manifestPath);

    foreach ($spool->chunkPaths as $chunkPath) {
        Storage::disk('local')->assertExists($chunkPath);
        $rows = json_decode(Storage::disk('local')->get($chunkPath), true, flags: JSON_THROW_ON_ERROR);

        expect($rows)->toHaveCount(1);
    }
});
