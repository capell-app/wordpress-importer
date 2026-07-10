<?php

declare(strict_types=1);

namespace Capell\WordPressImporter\Listeners;

use Capell\Core\Models\Page;
use Capell\MigrationAssistant\Events\ImportCompleting;
use Capell\MigrationAssistant\Models\ImportRollbackReport;
use Capell\WordPressImporter\Actions\CreateWordPressPermalinkRedirectsAction;
use Capell\WordPressImporter\Data\WordPressPermalinkRedirectReportData;

final class CreateWordPressRedirectsForCompletingImport
{
    public function handle(ImportCompleting $event): void
    {
        $createdPageIds = $this->createdPageIds($event);

        if ($createdPageIds === []) {
            return;
        }

        $pages = Page::query()
            ->withoutGlobalScopes()
            ->with('pageUrls')
            ->whereIn((new Page)->getKeyName(), $createdPageIds)
            ->get();

        $report = CreateWordPressPermalinkRedirectsAction::run($pages, $event->session->user_id);

        $this->storeReport($event, $report);
    }

    /**
     * @return list<int|string>
     */
    private function createdPageIds(ImportCompleting $event): array
    {
        $summary = is_array($event->session->result_summary) ? $event->session->result_summary : [];
        $createdPageIds = $summary['created_page_ids'] ?? [];

        if (! is_array($createdPageIds)) {
            return [];
        }

        return array_values(array_filter(
            $createdPageIds,
            static fn (mixed $pageId): bool => is_int($pageId) || (is_string($pageId) && trim($pageId) !== ''),
        ));
    }

    private function storeReport(ImportCompleting $event, WordPressPermalinkRedirectReportData $report): void
    {
        $reportPayload = $report->toArray();
        $summary = is_array($event->session->result_summary) ? $event->session->result_summary : [];
        $summary['wordpress_permalink_redirects'] = $reportPayload;

        $event->session
            ->forceFill(['result_summary' => $summary])
            ->save();

        $rollbackReport = $event->session
            ->rollbackReports()
            ->latest('id')
            ->first();

        if (! $rollbackReport instanceof ImportRollbackReport) {
            return;
        }

        $rollbackSummary = is_array($rollbackReport->summary) ? $rollbackReport->summary : [];
        $rollbackSummary['wordpress_permalink_redirects'] = $reportPayload;

        $rollbackReport
            ->forceFill([
                'created_models' => $this->mergeCreatedModels(
                    is_array($rollbackReport->created_models) ? $rollbackReport->created_models : [],
                    $report->createdModels(),
                ),
                'summary' => $rollbackSummary,
                'executed_at' => now(),
            ])
            ->save();
    }

    /**
     * @param  array<array-key, mixed>  $existingModels
     * @param  list<array{class: class-string, id: int|string}>  $newModels
     * @return list<array{class: string, id: int|string}>
     */
    private function mergeCreatedModels(array $existingModels, array $newModels): array
    {
        $merged = [];

        foreach ([...$existingModels, ...$newModels] as $model) {
            if (
                ! is_array($model)
                || ! is_string($model['class'] ?? null)
                || (! is_int($model['id'] ?? null) && ! is_string($model['id'] ?? null))
            ) {
                continue;
            }

            $merged[$model['class'] . ':' . (string) $model['id']] = [
                'class' => $model['class'],
                'id' => $model['id'],
            ];
        }

        return array_values($merged);
    }
}
