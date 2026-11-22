<?php

declare(strict_types=1);

namespace Capell\WordPressImporter\Listeners;

use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Page;
use Capell\MigrationAssistant\Events\ImportCompleting;
use Capell\WordPressImporter\Actions\ImportWordPressMediaForPagesAction;
use Capell\WordPressImporter\Providers\WordPressImporterServiceProvider;

final class ImportWordPressMediaForCompletingImport
{
    public function handle(ImportCompleting $event): void
    {
        // Kept on the cached check, not Core package admission: media import downloads over the
        // network, and admission would hold the lifecycle lock (blocking disable) for every download.
        if (! CapellCore::isPackageEnabled(WordPressImporterServiceProvider::$packageName)) {
            return;
        }

        $createdPageIds = $this->createdPageIds($event);

        if ($createdPageIds === []) {
            return;
        }

        $pages = Page::query()
            ->withoutGlobalScopes()
            ->whereIn((new Page)->getKeyName(), $createdPageIds)
            ->get();

        ImportWordPressMediaForPagesAction::run($pages, $event->session);
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
}
