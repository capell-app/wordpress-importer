<?php

declare(strict_types=1);

namespace Capell\WordPressImporter\Actions;

use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\UrlManager\Actions\UpsertRedirectRuleAction;
use Capell\UrlManager\Data\RedirectRuleData;
use Capell\WordPressImporter\Data\WordPressPermalinkRedirectReportData;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

/**
 * @method static WordPressPermalinkRedirectReportData run(iterable $pages, ?int $createdByUserId = null)
 */
final class CreateWordPressPermalinkRedirectsAction
{
    use AsObject;

    private const string REDIRECT_RULE_DATA = RedirectRuleData::class;

    private const string UPSERT_REDIRECT_RULE_ACTION = UpsertRedirectRuleAction::class;

    public function handle(iterable $pages, ?int $createdByUserId = null): WordPressPermalinkRedirectReportData
    {
        if (
            ! class_exists(self::REDIRECT_RULE_DATA)
            || ! class_exists(self::UPSERT_REDIRECT_RULE_ACTION)
            || ! Schema::hasTable('url_manager_redirect_rules')
        ) {
            return WordPressPermalinkRedirectReportData::empty();
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $createdRedirectRuleIds = [];
        $redirects = [];

        foreach ($pages as $page) {
            if (! $page instanceof Page) {
                continue;
            }

            $oldPermalink = $this->oldPermalink($page);
            $targetUrl = $this->targetUrl($page);
            if ($oldPermalink === null) {
                $skipped++;
                $redirects[] = $this->row($page, null, $targetUrl, 'skipped', 'missing_old_permalink');

                continue;
            }

            if ($targetUrl === null) {
                $skipped++;
                $redirects[] = $this->row($page, $oldPermalink, null, 'skipped', 'missing_target_url');

                continue;
            }

            if ($this->samePath($oldPermalink, $targetUrl)) {
                $skipped++;
                $redirects[] = $this->row($page, $oldPermalink, $targetUrl, 'skipped', 'same_target_path');

                continue;
            }

            $redirectRule = $this->upsertRedirect($oldPermalink, $targetUrl, $page, $createdByUserId);
            $redirectRuleId = $this->modelKey($redirectRule);
            $wasCreated = (bool) $redirectRule->wasRecentlyCreated;

            if ($wasCreated) {
                $created++;

                if ($redirectRuleId !== null) {
                    $createdRedirectRuleIds[] = $redirectRuleId;
                }
            } else {
                $updated++;
            }

            $redirects[] = $this->row(
                $page,
                $oldPermalink,
                $targetUrl,
                $wasCreated ? 'created' : 'updated',
                null,
                $redirectRuleId,
            );
        }

        return new WordPressPermalinkRedirectReportData(
            created: $created,
            updated: $updated,
            skipped: $skipped,
            createdRedirectRuleIds: array_values(array_unique($createdRedirectRuleIds)),
            redirects: $redirects,
        );
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

    private function upsertRedirect(string $oldPermalink, string $targetUrl, Page $page, ?int $createdByUserId): Model
    {
        $dataClass = self::REDIRECT_RULE_DATA;
        $actionClass = self::UPSERT_REDIRECT_RULE_ACTION;

        $redirectRule = $actionClass::run(new $dataClass(
            sourceUrl: $oldPermalink,
            targetUrl: $targetUrl,
            siteId: is_numeric($page->getAttribute('site_id')) ? (int) $page->getAttribute('site_id') : null,
            languageId: $this->languageId($page),
            notes: (string) __('capell-wordpress-importer::commands.import.redirect_note'),
            createdByUserId: $createdByUserId,
        ));

        throw_unless($redirectRule instanceof Model, RuntimeException::class, 'URL Manager redirect upsert did not return a model.');

        return $redirectRule;
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

    /**
     * @return array{page_id: int|string|null, old_url: string|null, target_url: string|null, status: string, reason: string|null, redirect_rule_id: int|string|null, site_id: int|null, language_id: int|null}
     */
    private function row(
        Page $page,
        ?string $oldUrl,
        ?string $targetUrl,
        string $status,
        ?string $reason,
        int|string|null $redirectRuleId = null,
    ): array {
        return [
            'page_id' => $this->modelKey($page),
            'old_url' => $oldUrl,
            'target_url' => $targetUrl,
            'status' => $status,
            'reason' => $reason,
            'redirect_rule_id' => $redirectRuleId,
            'site_id' => is_numeric($page->getAttribute('site_id')) ? (int) $page->getAttribute('site_id') : null,
            'language_id' => $this->languageId($page),
        ];
    }

    private function modelKey(Model $model): int|string|null
    {
        $key = $model->getKey();

        return is_int($key) || is_string($key) ? $key : null;
    }
}
