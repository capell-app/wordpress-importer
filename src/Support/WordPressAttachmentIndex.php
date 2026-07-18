<?php

declare(strict_types=1);

namespace Capell\WordPressImporter\Support;

use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

final class WordPressAttachmentIndex
{
    private readonly string $directory;

    private int $count = 0;

    private int $bytes = 0;

    public function __construct(
        private readonly int $maximumAttachments,
        private readonly int $maximumAttachmentsPerParent,
        private readonly int $maximumBytes,
    ) {
        throw_if($maximumAttachments < 1, RuntimeException::class, 'WordPress attachment index maximum must be positive.');
        throw_if($maximumAttachmentsPerParent < 1, RuntimeException::class, 'WordPress per-parent attachment index maximum must be positive.');
        throw_if($maximumBytes < 1, RuntimeException::class, 'WordPress attachment index byte maximum must be positive.');

        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'capell-wp-attachments-' . Str::random(32);

        throw_unless(@mkdir($this->directory, 0700), RuntimeException::class, 'WordPress attachment index directory could not be created.');
    }

    public function __destruct()
    {
        $this->close();
    }

    public function add(string $parentId, string $url, string $title): void
    {
        throw_if($this->count >= $this->maximumAttachments, RuntimeException::class, 'WordPress export exceeds the configured attachment index limit.');

        $path = $this->path($parentId);
        $parentCount = is_file($path) ? $this->lineCount($path) : 0;

        throw_if($parentCount >= $this->maximumAttachmentsPerParent, RuntimeException::class, sprintf(
            'WordPress export parent [%s] exceeds the configured per-parent attachment index limit.',
            $parentId,
        ));

        $encoded = json_encode(['url' => $url, 'title' => $title], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $record = $encoded . "\n";
        $recordBytes = strlen($record);

        throw_if($this->bytes + $recordBytes > $this->maximumBytes, RuntimeException::class, 'WordPress export exceeds the configured attachment index byte limit.');

        $written = file_put_contents($path, $record, FILE_APPEND | LOCK_EX);

        throw_if($written === false, RuntimeException::class, 'WordPress attachment index could not be written.');

        $this->count++;
        $this->bytes += $recordBytes;
    }

    /** @return list<array{url: string, title: string}> */
    public function forParent(string $parentId): array
    {
        $path = $this->path($parentId);

        if (! is_file($path)) {
            return [];
        }

        $handle = fopen($path, 'rb');
        throw_unless(is_resource($handle), RuntimeException::class, 'WordPress attachment index could not be read.');

        $attachments = [];

        try {
            while (($line = fgets($handle)) !== false) {
                try {
                    $attachment = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                } catch (JsonException $exception) {
                    throw new RuntimeException('WordPress attachment index contains invalid data.', previous: $exception);
                }

                if (! is_array($attachment) || ! is_string($attachment['url'] ?? null) || ! is_string($attachment['title'] ?? null)) {
                    throw new RuntimeException('WordPress attachment index contains invalid data.');
                }

                $attachments[] = [
                    'url' => $attachment['url'],
                    'title' => $attachment['title'],
                ];
            }
        } finally {
            fclose($handle);
        }

        return $attachments;
    }

    public function count(): int
    {
        return $this->count;
    }

    public function close(): void
    {
        if (! is_dir($this->directory)) {
            return;
        }

        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*.jsonl') ?: [] as $path) {
            @unlink($path);
        }

        @rmdir($this->directory);
    }

    private function path(string $parentId): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . hash('sha256', $parentId) . '.jsonl';
    }

    private function lineCount(string $path): int
    {
        $handle = fopen($path, 'rb');
        throw_unless(is_resource($handle), RuntimeException::class, 'WordPress attachment index could not be read.');

        $count = 0;

        try {
            while (fgets($handle) !== false) {
                $count++;
            }
        } finally {
            fclose($handle);
        }

        return $count;
    }
}
