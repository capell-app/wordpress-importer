<?php

declare(strict_types=1);

namespace Capell\WordPressImporter\Data;

use Capell\MigrationAssistant\Data\ExternalImportPreview;
use Capell\MigrationAssistant\Data\ExternalImportReadResult;

final readonly class WordPressImportPreviewData
{
    public function __construct(
        public string $path,
        public ExternalImportReadResult $readResult,
        public ExternalImportPreview $preview,
    ) {}
}
