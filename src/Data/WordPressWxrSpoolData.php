<?php

declare(strict_types=1);

namespace Capell\WordPressImporter\Data;

final readonly class WordPressWxrSpoolData
{
    /**
     * @param  list<string>  $chunkPaths
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $disk,
        public string $directory,
        public string $manifestPath,
        public array $chunkPaths,
        public array $metadata,
        public int $rowCount,
        public string $sourceFilename,
        public string $sourceChecksum,
    ) {}
}
