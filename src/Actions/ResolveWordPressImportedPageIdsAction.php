<?php

declare(strict_types=1);

namespace Capell\WordPressImporter\Actions;

use Capell\Core\Models\Page;
use Capell\MigrationAssistant\Models\ImportSession;
use Illuminate\Database\Eloquent\Builder;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static list<int|string> run(list<array<string, mixed>> $rows, ImportSession $session, ?int $siteId = null)
 */
final class ResolveWordPressImportedPageIdsAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<int|string>
     */
    public function handle(array $rows, ImportSession $session, ?int $siteId = null): array
    {
        $sourceIdentities = array_values(array_filter(
            array_map(
                static fn (array $row): mixed => $row['source_identity'] ?? null,
                $rows,
            ),
            static fn (mixed $sourceIdentity): bool => is_string($sourceIdentity) && $sourceIdentity !== '',
        ));

        if ($sourceIdentities === []) {
            return [];
        }

        return array_values(Page::query()
            ->withoutGlobalScopes()
            ->whereIn('meta->wordpress->source_identity', $sourceIdentities)
            ->where('created_at', '>=', $session->created_at?->subSecond() ?? now()->subSecond())
            ->when($siteId !== null, fn (Builder $builder): Builder => $builder->where('site_id', $siteId))
            ->pluck((new Page)->getKeyName())
            ->filter(static fn (mixed $pageId): bool => is_int($pageId) || is_string($pageId))
            ->values()
            ->all());
    }
}
