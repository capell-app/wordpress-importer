<?php

declare(strict_types=1);

namespace Capell\WordPressImporter\Actions;

use Capell\Core\Models\Page;
use Capell\MigrationAssistant\Data\ExternalImportPreview;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static ExternalImportPreview run(ExternalImportPreview $preview, ?int $siteId = null)
 */
final class ApplyWordPressPreviewIdempotencyAction
{
    use AsFake;
    use AsObject;

    public function handle(ExternalImportPreview $preview, ?int $siteId = null): ExternalImportPreview
    {
        // A context-free preview cannot inspect other sites to decide whether a post exists.
        if ($siteId === null) {
            return $preview;
        }
        $existingSourceIdentities = $this->existingSourceIdentities($preview, $siteId);

        if ($existingSourceIdentities === []) {
            return $preview;
        }

        $rows = [];
        $creates = 0;
        $skips = $preview->skips;

        foreach ($preview->rows as $row) {
            $sourceIdentity = $this->sourceIdentity($row);

            if ($sourceIdentity !== null && in_array($sourceIdentity, $existingSourceIdentities, true)) {
                $row['action'] = 'skip';
                $row['skip_reason'] = (string) __('capell-wordpress-importer::commands.import.duplicate_source_identity', [
                    'source' => $sourceIdentity,
                ]);
                $skips++;
            } else {
                $creates++;
            }

            $rows[] = $row;
        }

        return new ExternalImportPreview(
            target: $preview->target,
            creates: $creates,
            skips: $skips,
            rows: $rows,
            errors: $preview->errors,
        );
    }

    /** @param array<string, mixed> $wordpress */
    private static function key(array $wordpress): ?string
    {
        $identity = $wordpress['source_identity'] ?? null;
        $source = $wordpress['source_key'] ?? null;

        return is_string($identity) && $identity !== '' && is_string($source) && $source !== ''
            ? $source . ':' . $identity
            : null;
    }

    /**
     * @return list<string>
     */
    private function existingSourceIdentities(ExternalImportPreview $preview, int $siteId): array
    {
        $sourceIdentities = array_values(array_filter(
            array_map($this->sourceIdentity(...), $preview->rows),
            static fn (?string $sourceIdentity): bool => $sourceIdentity !== null,
        ));

        if ($sourceIdentities === []) {
            return [];
        }

        return array_values(Page::query()
            ->withoutGlobalScopes()
            ->where('site_id', $siteId)
            ->whereIn('meta->wordpress->source_identity', array_values(array_filter(array_map(
                static fn (array $row): mixed => data_get($row, 'attributes.meta.wordpress.source_identity'),
                $preview->rows,
            ), is_string(...))))
            ->toBase()
            ->pluck('meta')
            ->map(static function (mixed $meta) use ($preview): mixed {
                $decoded = is_string($meta) ? json_decode($meta, true) : $meta;

                if (! is_array($decoded) || ! is_array($decoded['wordpress'] ?? null)) {
                    return null;
                }

                /** @var array<string, mixed> $wordpress */
                $wordpress = $decoded['wordpress'];
                $key = self::key($wordpress);
                if ($key !== null) {
                    return $key;
                }
                // Legacy rows have no site key. Only the same non-empty source permalink proves a match.
                $permalink = $wordpress['old_permalink'] ?? null;
                if (! is_string($permalink) || $permalink === '') {
                    return null;
                }
                foreach ($preview->rows as $row) {
                    $incoming = data_get($row, 'attributes.meta.wordpress');
                    if (is_array($incoming) && ($incoming['source_identity'] ?? null) === ($wordpress['source_identity'] ?? null)
                        && ($incoming['old_permalink'] ?? null) === $permalink) {
                        return self::key($incoming);
                    }
                }

                return null;
            })
            ->filter(static fn (mixed $sourceIdentity): bool => is_string($sourceIdentity) && $sourceIdentity !== '')
            ->unique()
            ->all());
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function sourceIdentity(array $row): ?string
    {
        $attributes = is_array($row['attributes'] ?? null) ? $row['attributes'] : [];
        $meta = is_array($attributes['meta'] ?? null) ? $attributes['meta'] : [];
        $wordpress = is_array($meta['wordpress'] ?? null) ? $meta['wordpress'] : [];

        return self::key($wordpress);
    }
}
