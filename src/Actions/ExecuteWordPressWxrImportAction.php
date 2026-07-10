<?php

declare(strict_types=1);

namespace Capell\WordPressImporter\Actions;

use Capell\MigrationAssistant\Actions\Imports\ExecuteExternalPageImportAction;
use Capell\MigrationAssistant\Data\ExternalPageImportTargetData;
use Capell\MigrationAssistant\Data\Imports\ExternalPageImportExecutionResult;
use Illuminate\Contracts\Auth\Authenticatable;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static ExternalPageImportExecutionResult run(string $path, ExternalPageImportTargetData $target, ?Authenticatable $actor = null)
 */
final class ExecuteWordPressWxrImportAction
{
    use AsObject;

    public function handle(
        string $path,
        ExternalPageImportTargetData $target,
        ?Authenticatable $actor = null,
    ): ExternalPageImportExecutionResult {
        $importPreview = BuildWordPressImportPreviewAction::run($path);

        return ExecuteExternalPageImportAction::run(
            $importPreview->preview,
            $target,
            $actor,
            sourceFilename: basename($importPreview->path),
            targetLabel: (string) __('capell-wordpress-importer::commands.import.target_label'),
        );
    }
}
