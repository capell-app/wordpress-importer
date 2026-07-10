<?php

declare(strict_types=1);

namespace Capell\WordPressImporter\Listeners;

use Capell\Core\Models\Page;
use Capell\MigrationAssistant\Events\ImportCompleted;
use Capell\MigrationAssistant\Models\ImportRollbackReport;
use Capell\WordPressImporter\Actions\CreateWordPressPermalinkRedirectsAction;
use Capell\WordPressImporter\Data\WordPressPermalinkRedirectReportData;

final class CreateWordPressRedirectsForCompletedImport
{
    public function handle(ImportCompleted $event): void
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
    private function createdPageIds(ImportCompleted $event): array
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

    private function storeReport(ImportCompleted $event, WordPressPermalinkRedirectReportData $report): void
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
                'summary' => $rollbackSummary,
            ])
            ->save();
    }
}
