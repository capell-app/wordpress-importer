<?php

declare(strict_types=1);

namespace Capell\WordPressImporter\Actions;

use Capell\MigrationAssistant\Actions\CreateImportRollbackReportAction;
use Capell\MigrationAssistant\Actions\Imports\ExecuteExternalPageImportAction;
use Capell\MigrationAssistant\Data\ExternalImportReadResult;
use Capell\MigrationAssistant\Enums\ImportSessionStatus;
use Capell\MigrationAssistant\Events\ImportCompleted;
use Capell\MigrationAssistant\Events\ImportCompleting;
use Capell\MigrationAssistant\Events\ImportFailed;
use Capell\MigrationAssistant\Models\ImportSession;
use Capell\MigrationAssistant\Services\Import\ExternalImportPreviewBuilder;
use Capell\MigrationAssistant\Services\Import\ImportExecutionReport;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

/** @method static ImportExecutionReport run(ImportSession $session) */
final class ExecuteWordPressSpoolSessionAction
{
    use AsFake;
    use AsObject;

    public function handle(ImportSession $session): ImportExecutionReport
    {
        $actor = $this->actor($session->user_id);
        $authorizedTarget = ReauthorizeWordPressWxrSessionAction::run($session, $actor);
        $manifest = $this->wordpressManifest($session);
        $chunkPaths = $this->chunkPaths($manifest);
        $defaultPageAttributes = $authorizedTarget->pageAttributes();
        $siteId = is_numeric($defaultPageAttributes['site_id']) ? (int) $defaultPageAttributes['site_id'] : null;
        $disk = Storage::disk($this->diskName($manifest));
        $summary = is_array($session->result_summary) ? $session->result_summary : [];
        $checkpoint = is_array($summary['wordpress_checkpoint'] ?? null) ? $summary['wordpress_checkpoint'] : [];
        $nextChunkIndex = is_numeric($checkpoint['next_chunk_index'] ?? null) ? (int) $checkpoint['next_chunk_index'] : 0;

        for ($chunkIndex = $nextChunkIndex; $chunkIndex < count($chunkPaths); $chunkIndex++) {
            $rows = $this->readChunk($disk, $chunkPaths[$chunkIndex]);
            $summary = $this->markActiveChunk($session, $summary, $chunkIndex, count($chunkPaths));
            $readResult = new ExternalImportReadResult(
                sourceType: 'wordpress-wxr-spool',
                columns: array_keys(BuildWordPressImportPreviewAction::fieldMapping()),
                rows: $rows,
                metadata: is_array($manifest['metadata'] ?? null) ? $manifest['metadata'] : [],
                suggestedTarget: 'page',
            );
            $preview = ApplyWordPressPreviewIdempotencyAction::run(
                resolve(ExternalImportPreviewBuilder::class)->build(
                    $readResult,
                    BuildWordPressImportPreviewAction::fieldMapping(),
                ),
            );
            $chunkResult = ExecuteExternalPageImportAction::run(
                preview: $preview,
                defaultPageAttributes: $defaultPageAttributes,
                existingSession: $session,
                finalize: false,
                actor: $actor,
            );
            $recoveredPageIds = ResolveWordPressImportedPageIdsAction::run($rows, $session, $siteId);
            $summary = $this->mergeChunkResult(
                $summary,
                $chunkResult->report,
                $preview->skips,
                $recoveredPageIds,
                $chunkIndex + 1,
                count($chunkPaths),
            );
            $session->forceFill(['result_summary' => $summary])->save();
        }

        $report = $this->reportFromSummary($summary);
        $parentUpdates = ResolveWordPressParentPagesAction::run(array_values($report->createdPageIds));
        $persistedSummary = is_array($session->result_summary) ? $session->result_summary : [];
        $summary = [
            ...$persistedSummary,
            ...$report->toArray(),
        ];
        $summary['wordpress_checkpoint'] = [
            ...(is_array($session->result_summary['wordpress_checkpoint'] ?? null) ? $session->result_summary['wordpress_checkpoint'] : []),
            'next_chunk_index' => count($chunkPaths),
            'total_chunks' => count($chunkPaths),
            'active_chunk_index' => null,
            'parent_links_resolved' => $parentUpdates,
        ];

        $session->forceFill([
            'result_summary' => $summary,
            'failure_reason' => $report->isSuccess() ? null : implode(' / ', array_slice($report->errors, 0, 5)),
        ])->save();

        if (! $report->isSuccess()) {
            $reason = (string) $session->failure_reason;
            $session->forceFill([
                'status' => ImportSessionStatus::Failed,
                'executed_at' => now(),
            ])->save();
            event(new ImportFailed($session, $reason));

            return $report;
        }

        if ($report->createdPageIds !== [] && ! $session->rollbackReports()->exists()) {
            CreateImportRollbackReportAction::run($session, $report);
        }

        event(new ImportCompleting($session->refresh()));

        $session->forceFill([
            'status' => ImportSessionStatus::Completed,
            'failure_reason' => null,
            'executed_at' => now(),
        ])->save();
        event(new ImportCompleted($session));

        return $report;
    }

    /** @return array<string, mixed> */
    private function wordpressManifest(ImportSession $session): array
    {
        $manifest = is_array($session->manifest) ? $session->manifest : [];
        $wordpressManifest = is_array($manifest['wordpress_wxr'] ?? null) ? $manifest['wordpress_wxr'] : [];

        throw_unless(($wordpressManifest['format'] ?? null) === 'capell-wordpress-wxr-spool-v1', RuntimeException::class, 'Import session does not contain a supported WordPress spool manifest.');

        return $wordpressManifest;
    }

    /** @param array<string, mixed> $manifest
     * @return list<string>
     */
    private function chunkPaths(array $manifest): array
    {
        $chunkPaths = $manifest['chunk_paths'] ?? [];

        throw_unless(is_array($chunkPaths), RuntimeException::class, 'WordPress spool chunk paths are invalid.');

        return array_values(array_filter($chunkPaths, is_string(...)));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readChunk(Filesystem $disk, string $chunkPath): array
    {
        throw_unless($disk->exists($chunkPath), RuntimeException::class, sprintf('WordPress spool chunk [%s] is missing.', $chunkPath));
        $contents = $disk->get($chunkPath);
        throw_unless(is_string($contents), RuntimeException::class, sprintf('WordPress spool chunk [%s] could not be read.', $chunkPath));
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        throw_unless(is_array($decoded), RuntimeException::class, sprintf('WordPress spool chunk [%s] is invalid.', $chunkPath));

        $rows = [];

        foreach ($decoded as $row) {
            if (! is_array($row)) {
                continue;
            }

            $normalized = [];
            foreach ($row as $key => $value) {
                if (is_string($key)) {
                    $normalized[$key] = $value;
                }
            }
            $rows[] = $normalized;
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function markActiveChunk(ImportSession $session, array $summary, int $chunkIndex, int $totalChunks): array
    {
        $checkpoint = is_array($summary['wordpress_checkpoint'] ?? null) ? $summary['wordpress_checkpoint'] : [];
        $checkpoint['active_chunk_index'] = $chunkIndex;
        $checkpoint['total_chunks'] = $totalChunks;
        $summary['wordpress_checkpoint'] = $checkpoint;
        $session->forceFill(['result_summary' => $summary])->save();

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  list<int|string>  $recoveredPageIds
     * @return array<string, mixed>
     */
    private function mergeChunkResult(
        array $summary,
        ImportExecutionReport $chunkReport,
        int $previewSkips,
        array $recoveredPageIds,
        int $nextChunkIndex,
        int $totalChunks,
    ): array {
        $createdPageIds = array_values(array_unique([
            ...$this->ids($summary['created_page_ids'] ?? []),
            ...$chunkReport->createdPageIds,
            ...$recoveredPageIds,
        ], SORT_REGULAR));
        $errors = array_values(array_merge($this->strings($summary['errors'] ?? []), $chunkReport->errors));
        $structuredErrors = array_values(array_merge(
            $this->structuredErrors($summary['structured_errors'] ?? null),
            $chunkReport->structuredErrors,
        ));
        $checkpoint = is_array($summary['wordpress_checkpoint'] ?? null) ? $summary['wordpress_checkpoint'] : [];

        return [
            ...$summary,
            'pages_created' => count($createdPageIds),
            'pages_skipped' => $this->integer($summary['pages_skipped'] ?? null) + $chunkReport->pagesSkipped + $previewSkips,
            'page_urls_created' => $this->integer($summary['page_urls_created'] ?? null) + $chunkReport->pageUrlsCreated,
            'media_reassigned' => $this->integer($summary['media_reassigned'] ?? null) + $chunkReport->mediaReassigned,
            'created_page_ids' => $createdPageIds,
            'created_site_ids' => [],
            'created_site_domain_ids' => [],
            'errors' => $errors,
            'structured_errors' => $structuredErrors,
            'wordpress_checkpoint' => [
                ...$checkpoint,
                'next_chunk_index' => $nextChunkIndex,
                'total_chunks' => $totalChunks,
                'active_chunk_index' => null,
            ],
        ];
    }

    /** @param array<string, mixed> $summary */
    private function reportFromSummary(array $summary): ImportExecutionReport
    {
        return new ImportExecutionReport(
            pagesCreated: count($this->ids($summary['created_page_ids'] ?? [])),
            pagesSkipped: $this->integer($summary['pages_skipped'] ?? null),
            createdPageIds: $this->ids($summary['created_page_ids'] ?? []),
            errors: $this->strings($summary['errors'] ?? []),
            pageUrlsCreated: $this->integer($summary['page_urls_created'] ?? null),
            mediaReassigned: $this->integer($summary['media_reassigned'] ?? null),
            structuredErrors: $this->structuredErrors($summary['structured_errors'] ?? null),
        );
    }

    /** @return list<int|string> */
    private function ids(mixed $value): array
    {
        return is_array($value)
            ? array_values(array_filter($value, static fn (mixed $id): bool => is_int($id) || is_string($id)))
            : [];
    }

    /** @return list<string> */
    private function strings(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
    }

    private function integer(mixed $value): int
    {
        return is_int($value) ? $value : 0;
    }

    /** @return list<array{entry: string, phase: string, message: string}> */
    private function structuredErrors(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $errors = [];

        foreach ($value as $error) {
            if (! is_array($error) || ! is_string($error['entry'] ?? null) || ! is_string($error['phase'] ?? null) || ! is_string($error['message'] ?? null)) {
                continue;
            }

            $errors[] = [
                'entry' => $error['entry'],
                'phase' => $error['phase'],
                'message' => $error['message'],
            ];
        }

        return $errors;
    }

    private function actor(mixed $identifier): ?Authenticatable
    {
        $modelClass = config('auth.providers.users.model');

        if ((! is_int($identifier) && ! is_string($identifier)) || ! is_string($modelClass) || ! is_a($modelClass, Model::class, true)) {
            return null;
        }

        $actor = $modelClass::query()->find($identifier);

        return $actor instanceof Authenticatable ? $actor : null;
    }

    /** @param array<string, mixed> $manifest */
    private function diskName(array $manifest): string
    {
        $disk = $manifest['disk'] ?? config('migration-assistant.disk', 'local');

        return is_string($disk) && $disk !== '' ? $disk : 'local';
    }
}
