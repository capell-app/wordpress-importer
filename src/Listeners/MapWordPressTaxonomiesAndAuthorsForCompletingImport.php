<?php

declare(strict_types=1);

namespace Capell\WordPressImporter\Listeners;

use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Page;
use Capell\MigrationAssistant\Actions\AppendImportRollbackModelsAction;
use Capell\MigrationAssistant\Events\ImportCompleting;
use Capell\MigrationAssistant\Models\ImportRollbackReport;
use Capell\WordPressImporter\Actions\MapWordPressTaxonomiesAndAuthorsAction;
use Capell\WordPressImporter\Actions\RecordWordPressImportBaselineAction;
use Capell\WordPressImporter\Providers\WordPressImporterServiceProvider;

final class MapWordPressTaxonomiesAndAuthorsForCompletingImport
{
    public function handle(ImportCompleting $event): void
    {
        if (! CapellCore::isPackageEnabled(WordPressImporterServiceProvider::$packageName)) {
            return;
        }

        $summary = is_array($event->session->result_summary) ? $event->session->result_summary : [];
        $ids = is_array($summary['created_page_ids'] ?? null) ? $summary['created_page_ids'] : [];
        $ids = array_values(array_filter($ids, static fn (mixed $id): bool => is_int($id) || (is_string($id) && trim($id) !== '')));

        if ($ids === []) {
            return;
        }

        $pages = Page::query()->withoutGlobalScopes()->whereIn((new Page)->getKeyName(), $ids)->get();
        $report = MapWordPressTaxonomiesAndAuthorsAction::run($pages);
        // Registered after the media listener, so the baseline holds the content as stored.
        RecordWordPressImportBaselineAction::run($pages->fresh() ?? $pages);

        $summary = is_array($event->session->refresh()->result_summary) ? $event->session->result_summary : [];
        $summary['wordpress_taxonomies_and_authors'] = $report->toArray();
        $event->session->forceFill(['result_summary' => $summary])->save();

        $rollbackReport = $event->session->rollbackReports()->latest('id')->first();
        if ($rollbackReport instanceof ImportRollbackReport && $report->createdModels !== []) {
            AppendImportRollbackModelsAction::run($rollbackReport, $report->createdModels);
        }
    }
}
