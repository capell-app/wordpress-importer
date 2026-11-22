<?php

declare(strict_types=1);

namespace Capell\WordPressImporter\Listeners;

use Capell\Core\Actions\Extensions\RunUnderPackageAdmissionAction;
use Capell\Core\Models\Page;
use Capell\MigrationAssistant\Actions\AppendImportRollbackModelsAction;
use Capell\MigrationAssistant\Events\ImportCompleting;
use Capell\MigrationAssistant\Models\ImportRollbackReport;
use Capell\WordPressImporter\Actions\CreateWordPressPermalinkRedirectsAction;
use Capell\WordPressImporter\Data\WordPressPermalinkRedirectReportData;
use Capell\WordPressImporter\Providers\WordPressImporterServiceProvider;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

final class CreateWordPressRedirectsForCompletingImport
{
    public function handle(ImportCompleting $event): void
    {
        // URL Manager refuses redirect writes inside a transaction, because its graph lock
        // must not be released before the write commits. Package admission runs its work
        // inside a transaction, so the redirects are written between two admitted phases:
        // one that reads the imported pages and one that records this package's report.
        if ($this->createdPageIds($event) === []) {
            return;
        }

        $admission = RunUnderPackageAdmissionAction::run(
            WordPressImporterServiceProvider::$packageName,
            fn (): Collection => $this->createdPages($event),
        );

        if (! $admission->admitted || ! $admission->value instanceof Collection) {
            return;
        }

        $report = CreateWordPressPermalinkRedirectsAction::run($admission->value, $event->session->user_id);

        RunUnderPackageAdmissionAction::run(
            WordPressImporterServiceProvider::$packageName,
            fn () => $this->storeReport($event, $report),
        );
    }

    /**
     * @return Collection<int, Page>
     */
    private function createdPages(ImportCompleting $event): Collection
    {
        return Page::query()
            ->withoutGlobalScopes()
            ->with('pageUrls')
            ->whereIn((new Page)->getKeyName(), $this->createdPageIds($event))
            ->get();
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

        $models = [];
        foreach ($report->createdModels() as $created) {
            $modelClass = $created['class'];
            if (! is_subclass_of($modelClass, Model::class)) {
                continue;
            }
            $model = $modelClass::query()->find($created['id']);
            if ($model instanceof Model) {
                $models[] = $model;
            }
        }
        $rollbackReport = AppendImportRollbackModelsAction::run($rollbackReport, $models);

        $rollbackSummary = is_array($rollbackReport->summary) ? $rollbackReport->summary : [];
        $rollbackSummary['wordpress_permalink_redirects'] = $reportPayload;

        $rollbackReport
            ->forceFill([
                'summary' => $rollbackSummary,
            ])
            ->save();
    }
}
