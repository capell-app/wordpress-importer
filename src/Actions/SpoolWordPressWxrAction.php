<?php

declare(strict_types=1);

namespace Capell\WordPressImporter\Actions;

use Capell\WordPressImporter\Data\WordPressWxrSpoolData;
use Capell\WordPressImporter\Services\WxrReader;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use JsonException;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/** @method static WordPressWxrSpoolData run(string $path) */
final class SpoolWordPressWxrAction
{
    use AsAction;

    public function handle(string $path): WordPressWxrSpoolData
    {
        $resolvedPath = realpath($path);
        throw_if($resolvedPath === false || ! is_readable($resolvedPath), RuntimeException::class, sprintf('WordPress export [%s] is not readable.', $path));

        $diskName = $this->diskName();
        $disk = Storage::disk($diskName);
        $directory = trim($this->basePath(), '/') . '/' . Str::uuid()->toString();
        $chunkPaths = [];
        $buffer = [];
        $chunkIndex = 0;
        $rowCount = 0;

        $metadata = resolve(WxrReader::class)->streamRows(
            $resolvedPath,
            function (array $row) use (&$buffer, &$chunkIndex, &$chunkPaths, &$rowCount, $directory, $disk): void {
                $buffer[] = $row;
                $rowCount++;

                if (count($buffer) < $this->chunkRows()) {
                    return;
                }

                $chunkPaths[] = $this->writeChunk($disk, $directory, $chunkIndex, $buffer);
                $chunkIndex++;
                $buffer = [];
            },
        );

        if ($buffer !== []) {
            $chunkPaths[] = $this->writeChunk($disk, $directory, $chunkIndex, $buffer);
        }

        $sourceChecksum = hash_file('sha256', $resolvedPath);
        throw_if(! is_string($sourceChecksum), RuntimeException::class, 'Unable to checksum the WordPress export.');

        $manifestPath = $directory . '/manifest.json';
        $manifest = [
            'format' => 'capell-wordpress-wxr-spool-v1',
            'source_filename' => basename($resolvedPath),
            'source_checksum' => $sourceChecksum,
            'row_count' => $rowCount,
            'chunk_paths' => $chunkPaths,
            'metadata' => $metadata,
        ];

        throw_unless(
            $disk->put($manifestPath, $this->encode($manifest)),
            RuntimeException::class,
            'Unable to persist the WordPress import spool manifest.',
        );

        return new WordPressWxrSpoolData(
            disk: $diskName,
            directory: $directory,
            manifestPath: $manifestPath,
            chunkPaths: $chunkPaths,
            metadata: $metadata,
            rowCount: $rowCount,
            sourceFilename: basename($resolvedPath),
            sourceChecksum: $sourceChecksum,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function writeChunk(Filesystem $disk, string $directory, int $index, array $rows): string
    {
        $chunkPath = sprintf('%s/chunks/%06d.json', $directory, $index);

        throw_unless(
            $disk->put($chunkPath, $this->encode($rows)),
            RuntimeException::class,
            sprintf('Unable to persist WordPress import spool chunk [%d].', $index),
        );

        return $chunkPath;
    }

    /** @param array<array-key, mixed> $value */
    private function encode(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            throw new RuntimeException('Unable to encode the WordPress import spool.', previous: $jsonException);
        }
    }

    private function diskName(): string
    {
        $configured = config('wordpress-importer.spool.disk');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $migrationDisk = config('migration-assistant.disk', 'local');

        return is_string($migrationDisk) && $migrationDisk !== '' ? $migrationDisk : 'local';
    }

    private function basePath(): string
    {
        $configured = config('wordpress-importer.spool.path', 'wordpress-importer/spools');

        return is_string($configured) && $configured !== '' ? $configured : 'wordpress-importer/spools';
    }

    private function chunkRows(): int
    {
        $configured = config('wordpress-importer.spool.chunk_rows', 100);

        return is_numeric($configured) ? max(1, (int) $configured) : 100;
    }
}
