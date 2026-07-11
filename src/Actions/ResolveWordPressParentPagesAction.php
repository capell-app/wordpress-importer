<?php

declare(strict_types=1);

namespace Capell\WordPressImporter\Actions;

use Capell\Core\Models\Page;
use Lorisleiva\Actions\Concerns\AsAction;

/** @method static int run(list<int|string> $pageIds) */
final class ResolveWordPressParentPagesAction
{
    use AsAction;

    /** @param list<int|string> $pageIds */
    public function handle(array $pageIds): int
    {
        if ($pageIds === []) {
            return 0;
        }

        $pages = Page::query()
            ->withoutGlobalScopes()
            ->with('site')
            ->whereKey($pageIds)
            ->get();
        $pageIdsBySourceIdentity = [];

        foreach ($pages as $page) {
            $sourceIdentity = $this->sourceIdentity($page);

            if ($sourceIdentity !== null) {
                $pageIdsBySourceIdentity[$sourceIdentity] = $page->getKey();
            }
        }

        $updated = 0;

        foreach ($pages as $page) {
            $parentSourceIdentity = $this->parentSourceIdentity($page);
            $parentId = $parentSourceIdentity === null ? null : ($pageIdsBySourceIdentity[$parentSourceIdentity] ?? null);

            if ($parentId === null || $page->getAttribute('parent_id') === $parentId) {
                continue;
            }

            $page->forceFill(['parent_id' => $parentId])->save();
            $updated++;
        }

        return $updated;
    }

    private function sourceIdentity(Page $page): ?string
    {
        $wordpress = $this->wordpressMeta($page);
        $sourceIdentity = $wordpress['source_identity'] ?? null;

        return is_string($sourceIdentity) && $sourceIdentity !== '' ? $sourceIdentity : null;
    }

    private function parentSourceIdentity(Page $page): ?string
    {
        $wordpress = $this->wordpressMeta($page);
        $parentId = $wordpress['parent_id'] ?? null;

        if ((! is_string($parentId) && ! is_int($parentId)) || trim((string) $parentId) === '' || trim((string) $parentId) === '0') {
            return null;
        }

        return 'wordpress:' . trim((string) $parentId);
    }

    /** @return array<string, mixed> */
    private function wordpressMeta(Page $page): array
    {
        $meta = is_array($page->getAttribute('meta')) ? $page->getAttribute('meta') : [];

        return is_array($meta['wordpress'] ?? null) ? $meta['wordpress'] : [];
    }
}
