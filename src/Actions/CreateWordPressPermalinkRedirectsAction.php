<?php

declare(strict_types=1);

namespace Capell\WordPressImporter\Actions;

use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Schema;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static int run(iterable $pages, ?int $createdByUserId = null)
 */
final class CreateWordPressPermalinkRedirectsAction
{
    use AsObject;

    private const string REDIRECT_RULE_DATA = 'Capell\\UrlManager\\Data\\RedirectRuleData';

    private const string UPSERT_REDIRECT_RULE_ACTION = 'Capell\\UrlManager\\Actions\\UpsertRedirectRuleAction';

    public function handle(iterable $pages, ?int $createdByUserId = null): int
    {
        if (
            ! class_exists(self::REDIRECT_RULE_DATA)
            || ! class_exists(self::UPSERT_REDIRECT_RULE_ACTION)
            || ! Schema::hasTable('url_manager_redirect_rules')
        ) {
            return 0;
        }

        $created = 0;

        foreach ($pages as $page) {
            if (! $page instanceof Page) {
                continue;
            }

            $oldPermalink = $this->oldPermalink($page);
            $targetUrl = $this->targetUrl($page);

            if ($oldPermalink === null || $targetUrl === null || $this->samePath($oldPermalink, $targetUrl)) {
                continue;
            }

            $this->upsertRedirect($oldPermalink, $targetUrl, $page, $createdByUserId);
            $created++;
        }

        return $created;
    }

    private function oldPermalink(Page $page): ?string
    {
        $meta = is_array($page->getAttribute('meta')) ? $page->getAttribute('meta') : [];
        $wordpress = is_array($meta['wordpress'] ?? null) ? $meta['wordpress'] : [];
        $oldPermalink = $wordpress['old_permalink'] ?? $wordpress['link'] ?? null;

        return is_string($oldPermalink) && trim($oldPermalink) !== '' ? trim($oldPermalink) : null;
    }

    private function targetUrl(Page $page): ?string
    {
        /** @var EloquentCollection<int, PageUrl>|null $loadedPageUrls */
        $loadedPageUrls = $page->relationLoaded('pageUrls') ? $page->getRelation('pageUrls') : null;
        $pageUrl = $loadedPageUrls instanceof EloquentCollection
            ? $loadedPageUrls->first(fn (PageUrl $candidate): bool => (bool) $candidate->getAttribute('status'))
            : null;

        if (! $pageUrl instanceof PageUrl) {
            $pageUrl = $page->pageUrls()
                ->where('status', true)
                ->oldest('id')
                ->first();
        }

        $targetUrl = $pageUrl?->getAttribute('url');

        return is_string($targetUrl) && trim($targetUrl) !== '' ? trim($targetUrl) : null;
    }

    private function samePath(string $oldPermalink, string $targetUrl): bool
    {
        $oldPath = parse_url($oldPermalink, PHP_URL_PATH);

        if (! is_string($oldPath) || trim($oldPath) === '') {
            return false;
        }

        return $this->normalizePath($oldPath) === $this->normalizePath($targetUrl);
    }

    private function normalizePath(string $path): string
    {
        $path = strtolower('/' . trim($path, '/'));

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    private function upsertRedirect(string $oldPermalink, string $targetUrl, Page $page, ?int $createdByUserId): void
    {
        $dataClass = self::REDIRECT_RULE_DATA;
        $actionClass = self::UPSERT_REDIRECT_RULE_ACTION;

        $actionClass::run(new $dataClass(
            sourceUrl: $oldPermalink,
            targetUrl: $targetUrl,
            siteId: is_numeric($page->getAttribute('site_id')) ? (int) $page->getAttribute('site_id') : null,
            languageId: $this->languageId($page),
            notes: (string) __('capell-wordpress-importer::commands.import.redirect_note'),
            createdByUserId: $createdByUserId,
        ));
    }

    private function languageId(Page $page): ?int
    {
        /** @var EloquentCollection<int, PageUrl>|null $loadedPageUrls */
        $loadedPageUrls = $page->relationLoaded('pageUrls') ? $page->getRelation('pageUrls') : null;
        $pageUrl = $loadedPageUrls instanceof EloquentCollection ? $loadedPageUrls->first() : null;

        if ($pageUrl instanceof PageUrl && is_numeric($pageUrl->getAttribute('language_id'))) {
            return (int) $pageUrl->getAttribute('language_id');
        }

        $languageId = $page->pageUrls()
            ->where('status', true)
            ->value('language_id');

        return is_numeric($languageId) ? (int) $languageId : null;
    }
}
