<?php

declare(strict_types=1);

namespace Capell\WordPressImporter\Actions;

use Capell\Core\Models\Page;
use Capell\MigrationAssistant\Models\ImportSession;
use Capell\WordPressImporter\Support\WordPressImportedFields;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Re-imports chosen fields onto pages a previous WordPress import created.
 *
 * A field is updated only when the export changed it since the last import and nobody has edited it on the
 * page since. A field an editor changed is reported and left alone, as is any page imported before the
 * import recorded what it stored. Title, content and excerpt can be updated; the address and the publication
 * state are editorial decisions and a re-import never changes them. Each update is saved as an ordinary page
 * save, so the page revision history holds the previous value.
 *
 * @method static array<string, mixed> run(list<array<string, mixed>> $rows, int $siteId, list<string> $fields, ?ImportSession $session = null)
 */
final class UpdateExistingWordPressPagesAction
{
    use AsFake;
    use AsObject;

    /** @var array<string, string> field => export row column */
    private const array ROW_COLUMNS = ['title' => 'post_title', 'content' => 'post_content', 'excerpt' => 'post_excerpt'];

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $fields
     * @return array{pages: list<array<string, mixed>>, updated_fields: int, edited_fields: int, unchanged_pages: int}
     */
    public function handle(array $rows, int $siteId, array $fields, ?ImportSession $session = null): array
    {
        $fields = array_values(array_intersect(WordPressImportedFields::FIELDS, $fields));
        $report = ['pages' => [], 'updated_fields' => 0, 'edited_fields' => 0, 'unchanged_pages' => 0];

        if ($fields === []) {
            return $report;
        }

        foreach ($rows as $row) {
            $identity = is_string($row['source_identity'] ?? null) ? $row['source_identity'] : '';
            $sourceKey = is_string($row['source_key'] ?? null) ? $row['source_key'] : '';
            if ($identity === '' || $sourceKey === '') {
                continue;
            }

            $page = Page::query()
                ->withoutGlobalScopes()
                ->where('site_id', $siteId)
                ->where('meta->wordpress->source_identity', $identity)
                ->where('meta->wordpress->source_key', $sourceKey)
                ->first();
            if (! $page instanceof Page) {
                continue;
            }

            $result = $this->updatePage($page, $row, $fields, $session);
            $report['updated_fields'] += count($result['updated']);
            $report['edited_fields'] += count(array_filter($result['skipped'], static fn (string $reason): bool => $reason === 'edited'));
            if ($result['updated'] === [] && $result['skipped'] === []) {
                $report['unchanged_pages']++;

                continue;
            }

            $report['pages'][] = ['page_id' => $page->getKey(), 'source_identity' => $identity, ...$result];
        }

        return $report;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $fields
     * @return array{updated: list<string>, skipped: array<string, string>}
     */
    private function updatePage(Page $page, array $row, array $fields, ?ImportSession $session): array
    {
        $meta = is_array($page->meta) ? $page->meta : [];
        $wordpress = is_array($meta['wordpress'] ?? null) ? $meta['wordpress'] : [];
        $sourceHashes = is_array($wordpress['field_hashes'] ?? null) ? $wordpress['field_hashes'] : [];
        $storedHashes = is_array($wordpress['stored_hashes'] ?? null) ? $wordpress['stored_hashes'] : [];
        $currentHashes = WordPressImportedFields::hashes($page);
        $incoming = [];
        $updated = [];
        $skipped = [];

        foreach ($fields as $field) {
            $value = is_string($row[self::ROW_COLUMNS[$field]] ?? null) ? $row[self::ROW_COLUMNS[$field]] : '';
            $incomingHash = hash('sha256', $value);

            if (($sourceHashes[$field] ?? null) === $incomingHash) {
                continue;
            }

            if (! is_string($storedHashes[$field] ?? null)) {
                $skipped[$field] = 'no_baseline';

                continue;
            }

            if ($storedHashes[$field] !== $currentHashes[$field]) {
                $skipped[$field] = 'edited';

                continue;
            }

            $incoming[$field] = $value;
            $sourceHashes[$field] = $incomingHash;
            $updated[] = $field;
        }

        if ($incoming === []) {
            return ['updated' => [], 'skipped' => $skipped];
        }

        $this->apply($page, $incoming);

        if (array_key_exists('content', $incoming)) {
            // New content may point at images still on the old site; fetch them as the first import did.
            $meta = is_array($page->refresh()->meta) ? $page->meta : [];
            $wordpress = is_array($meta['wordpress'] ?? null) ? $meta['wordpress'] : [];
            $wordpress['attachments'] = is_array($row['attachments'] ?? null) ? $row['attachments'] : [];
            $wordpress['media_urls'] = is_array($row['media_urls'] ?? null) ? $row['media_urls'] : [];
            $meta['wordpress'] = $wordpress;
            $page->forceFill(['meta' => $meta])->saveQuietly();
            ImportWordPressMediaForPagesAction::run([$page->refresh()], $session);
        }

        $meta = is_array($page->refresh()->meta) ? $page->meta : [];
        $wordpress = is_array($meta['wordpress'] ?? null) ? $meta['wordpress'] : [];
        $wordpress['field_hashes'] = $sourceHashes;
        $wordpress['stored_hashes'] = WordPressImportedFields::hashes($page);
        $meta['wordpress'] = $wordpress;
        $page->forceFill(['meta' => $meta])->saveQuietly();

        return ['updated' => $updated, 'skipped' => $skipped];
    }

    /**
     * @param  array<string, string>  $incoming
     */
    private function apply(Page $page, array $incoming): void
    {
        $meta = is_array($page->meta) ? $page->meta : [];
        $translation = $page->translations()->orderBy('id')->first();

        if (array_key_exists('title', $incoming)) {
            $page->name = $incoming['title'];
            $translation?->forceFill(['title' => $incoming['title']])->save();
        }

        if (array_key_exists('content', $incoming)) {
            $meta['content'] = $incoming['content'];
            $translation?->forceFill(['content' => $incoming['content']])->save();
        }

        if (array_key_exists('excerpt', $incoming)) {
            $meta['excerpt'] = $incoming['excerpt'];
        }

        $page->forceFill(['meta' => $meta])->save();
    }
}
