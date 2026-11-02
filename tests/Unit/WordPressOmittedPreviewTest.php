<?php

declare(strict_types=1);

namespace Tests\Packages\WordPressImporter\Unit;

use Capell\MigrationAssistant\Data\ExternalImportReadResult;
use Capell\WordPressImporter\Services\WxrReader;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;

it('reports bounded malformed items in both preview formats and reader paths', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'wxr-omitted-');
    expect($path)->not->toBeFalse();
    file_put_contents($path, <<<'XML'
<rss xmlns:wp="http://wordpress.org/export/1.2/"><channel><title>Site</title><wp:wxr_version>1.2</wp:wxr_version>
<item><title>Valid</title><wp:post_id>1</wp:post_id><wp:post_type>post</wp:post_type></item>
<item><title></title><wp:post_id>2</wp:post_id><wp:post_type>page</wp:post_type></item>
<item><title>Private title</title><wp:post_type>post</wp:post_type></item>
<item><title>Ignored</title><wp:post_type>attachment</wp:post_type></item>
</channel></rss>
XML);
    try {
        config()->set('wordpress-importer.spool.max_stored_item_errors', 1);
        $reader = new WxrReader;
        $dom = (new ReflectionMethod(WxrReader::class, 'readDom'))->invoke($reader, $path);
        $stream = $reader->read($path);
        $streamRows = [];
        $streamMetadata = $reader->streamRows($path, static function (array $row) use (&$streamRows): void {
            $streamRows[] = $row;
        });
        expect($streamRows)->toHaveCount(1)
            ->and($streamMetadata['skipped_item_count'])->toBe(2)
            ->and($streamMetadata['item_errors'])->toBe(['Item 2 skipped: missing title']);
        foreach ([$dom, $stream] as $result) {
            assert($result instanceof ExternalImportReadResult);
            expect($result->count())->toBe(1)
                ->and($result->metadata['skipped_item_count'])->toBe(2)
                ->and($result->metadata['item_errors'])->toBe(['Item 2 skipped: missing title']);
        }
        $writes = [];
        DB::listen(static function (QueryExecuted $query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql) === 1) {
                $writes[] = $query->sql;
            }
        });
        expect(Artisan::call('wordpress-importer:import', ['path' => $path, '--json' => true]))->toBe(0);
        /** @var array<string, mixed> $json */
        $json = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        expect($json['creates'])->toBe(1)->and($json['skips'])->toBe(0)
            ->and($json['omitted_items'])->toBe(['count' => 2, 'errors' => ['Item 2 skipped: missing title']]);
        expect(Artisan::call('wordpress-importer:import', ['path' => $path]))->toBe(0);
        $output = Artisan::output();
        expect($output)->toContain('2 malformed page or post item(s)', 'Item 2 skipped: missing title')
            ->not->toContain('Private title');
        expect($writes)->toBe([]);
        config()->set('wordpress-importer.spool.max_stored_item_errors', 10);
        foreach ([$reader->read($path), (new ReflectionMethod(WxrReader::class, 'readDom'))->invoke($reader, $path)] as $result) {
            assert($result instanceof ExternalImportReadResult);
            expect($result->metadata['item_errors'])->toBe(['Item 2 skipped: missing title', 'Item 3 skipped: missing wp:post_id']);
        }
    } finally {
        unlink($path);
    }
});
