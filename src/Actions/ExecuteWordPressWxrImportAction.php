<?php

declare(strict_types=1);

namespace Capell\WordPressImporter\Actions;

use Capell\MigrationAssistant\Actions\Imports\AuthorizeExternalPageImportTargetAction;
use Capell\MigrationAssistant\Contracts\PageImportTargetResolver;
use Capell\MigrationAssistant\Data\ExternalPageImportTargetData;
use Capell\MigrationAssistant\Data\Imports\ExternalPageImportExecutionResult;
use Capell\MigrationAssistant\Enums\ImportSessionKind;
use Capell\MigrationAssistant\Enums\ImportSessionStatus;
use Capell\MigrationAssistant\Events\ImportFailed;
use Capell\MigrationAssistant\Models\ImportSession;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

/**
 * @method static ExternalPageImportExecutionResult run(string $path, ExternalPageImportTargetData $requestedTarget, ?Authenticatable $actor = null)
 */
final class ExecuteWordPressWxrImportAction
{
    use AsFake;
    use AsObject;

    public function handle(
        string $path,
        ExternalPageImportTargetData $requestedTarget,
        ?Authenticatable $actor = null,
    ): ExternalPageImportExecutionResult {
        $targetData = AuthorizeExternalPageImportTargetAction::run($requestedTarget, $actor);
        $spool = SpoolWordPressWxrAction::run($path);
        $defaultPageAttributes = $targetData->pageAttributes();

        $target = resolve(PageImportTargetResolver::class)->create(
            (string) __('capell-wordpress-importer::commands.import.target_label'),
        );
        $session = ImportSession::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $actor?->getAuthIdentifier() ?? auth()->id(),
            'target_type' => $target->type,
            'target_id' => is_int($target->id) ? $target->id : null,
            'target_label' => $target->label,
            'target_url' => $target->url,
            'kind' => ImportSessionKind::PageImport,
            'status' => ImportSessionStatus::Running,
            'source_environment' => 'wordpress-wxr',
            'source_filename' => $spool->sourceFilename,
            'source_package_path' => $spool->manifestPath,
            'source_package_checksum' => $spool->sourceChecksum,
            'working_dir' => $spool->directory,
            'manifest' => [
                'package_type' => 'external-page-import',
                'target' => 'page',
                'creates' => $spool->rowCount,
                'skips' => 0,
                'wordpress_wxr' => [
                    'format' => 'capell-wordpress-wxr-spool-v1',
                    'disk' => $spool->disk,
                    'chunk_paths' => $spool->chunkPaths,
                    'metadata' => $spool->metadata,
                    'default_page_attributes' => $defaultPageAttributes,
                ],
            ],
            'resolution_map' => ['resolved' => [], 'unresolved' => []],
            'page_decisions' => [],
            'relation_decisions' => [],
            'validation_results' => ['blocking_errors' => []],
            'result_summary' => [
                'wordpress_checkpoint' => [
                    'next_chunk_index' => 0,
                    'total_chunks' => count($spool->chunkPaths),
                    'active_chunk_index' => null,
                ],
            ],
            'reviewed_at' => now(),
            'validated_at' => now(),
        ]);

        try {
            $report = ExecuteWordPressSpoolSessionAction::run($session);

            return new ExternalPageImportExecutionResult($session->refresh(), $report);
        } catch (Throwable $throwable) {
            $session->forceFill([
                'status' => ImportSessionStatus::Failed,
                'failure_reason' => $throwable->getMessage(),
            ])->save();
            event(new ImportFailed($session, $throwable->getMessage()));

            throw $throwable;
        }
    }
}
