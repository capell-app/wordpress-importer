<?php

declare(strict_types=1);

namespace Capell\WordPressImporter\Actions;

use Capell\Core\Models\Page;
use Capell\WordPressImporter\Support\WordPressImportedFields;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Records what each imported page held when the import finished, so a later re-import can tell a field an
 * editor changed from one that is still as imported.
 *
 * @method static int run(iterable<array-key, mixed> $pages)
 */
final class RecordWordPressImportBaselineAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  iterable<array-key, mixed>  $pages
     */
    public function handle(iterable $pages): int
    {
        $recorded = 0;

        foreach ($pages as $page) {
            if (! $page instanceof Page || ! is_array(data_get($page->meta, 'wordpress'))) {
                continue;
            }

            $meta = is_array($page->meta) ? $page->meta : [];
            $wordpress = is_array($meta['wordpress'] ?? null) ? $meta['wordpress'] : [];
            $wordpress['stored_hashes'] = WordPressImportedFields::hashes($page);
            $meta['wordpress'] = $wordpress;
            $page->forceFill(['meta' => $meta])->saveQuietly();
            $recorded++;
        }

        return $recorded;
    }
}
