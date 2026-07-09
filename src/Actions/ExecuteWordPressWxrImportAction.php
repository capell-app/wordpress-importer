<?php

declare(strict_types=1);

namespace Capell\WordPressImporter\Actions;

use Capell\MigrationAssistant\Actions\Imports\ExecuteExternalPageImportAction;
use Capell\MigrationAssistant\Data\Imports\ExternalPageImportExecutionResult;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static ExternalPageImportExecutionResult run(string $path, int $siteId, int $layoutId, int $typeId, ?int $languageId = null)
 */
final class ExecuteWordPressWxrImportAction
{
    use AsObject;

    public function handle(
        string $path,
        int $siteId,
        int $layoutId,
        int $typeId,
        ?int $languageId = null,
    ): ExternalPageImportExecutionResult {
        $importPreview = BuildWordPressImportPreviewAction::run($path);
        $defaultPageAttributes = [
            'site_id' => $siteId,
            'layout_id' => $layoutId,
            'blueprint_id' => $typeId,
        ];

        if ($languageId !== null) {
            $defaultPageAttributes['language_id'] = $languageId;
        }

        return ExecuteExternalPageImportAction::run(
            $importPreview->preview,
            $defaultPageAttributes,
            sourceFilename: basename($importPreview->path),
            targetLabel: (string) __('capell-wordpress-importer::commands.import.target_label'),
        );
    }
}
