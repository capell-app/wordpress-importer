<?php

declare(strict_types=1);

namespace Capell\WordPressImporter\Listeners;

use Capell\Core\Models\Page;
use Capell\MigrationAssistant\Events\ImportCompleted;
use Capell\WordPressImporter\Actions\CreateWordPressPermalinkRedirectsAction;

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

        CreateWordPressPermalinkRedirectsAction::run($pages, $event->session->user_id);
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
}
