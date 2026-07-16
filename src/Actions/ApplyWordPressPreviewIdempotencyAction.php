<?php

declare(strict_types=1);

namespace Capell\WordPressImporter\Actions;

use Capell\Core\Models\Page;
use Capell\MigrationAssistant\Data\ExternalImportPreview;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static ExternalImportPreview run(ExternalImportPreview $preview)
 */
final class ApplyWordPressPreviewIdempotencyAction
{
    use AsFake;
    use AsObject;

    public function handle(ExternalImportPreview $preview): ExternalImportPreview
    {
        $existingSourceIdentities = $this->existingSourceIdentities($preview);

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

    /**
     * @return list<string>
     */
    private function existingSourceIdentities(ExternalImportPreview $preview): array
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
            ->whereIn('meta->wordpress->source_identity', $sourceIdentities)
            ->pluck('meta->wordpress->source_identity')
            ->filter(fn (mixed $sourceIdentity): bool => is_string($sourceIdentity) && $sourceIdentity !== '')
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
        $sourceIdentity = $wordpress['source_identity'] ?? null;

        return is_string($sourceIdentity) && trim($sourceIdentity) !== '' ? trim($sourceIdentity) : null;
    }
}
