<?php

declare(strict_types=1);

namespace Capell\WordPressImporter\Data;

use Capell\MigrationAssistant\Data\ExternalImportReadResult;

final readonly class WordPressWxrReadData
{
    public function __construct(
        public string $path,
        public ExternalImportReadResult $readResult,
    ) {}
}
