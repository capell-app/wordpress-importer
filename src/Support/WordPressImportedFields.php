<?php

declare(strict_types=1);

namespace Capell\WordPressImporter\Support;

use Capell\Core\Models\Page;
use Capell\Core\Models\Translation;

/**
 * The page values a WordPress re-import may update, read the way an editor changes them.
 */
final class WordPressImportedFields
{
    public const array FIELDS = ['title', 'content', 'excerpt'];

    /**
     * @return array{title: string, content: string, excerpt: string}
     */
    public static function current(Page $page): array
    {
        $translation = $page->translations()->orderBy('id')->first();
        $meta = is_array($page->meta) ? $page->meta : [];

        return [
            'title' => (string) $page->name,
            'content' => $translation instanceof Translation
                ? (is_string($translation->content) ? $translation->content : '')
                : (is_string($meta['content'] ?? null) ? $meta['content'] : ''),
            'excerpt' => is_string($meta['excerpt'] ?? null) ? $meta['excerpt'] : '',
        ];
    }

    /**
     * @return array{title: string, content: string, excerpt: string}
     */
    public static function hashes(Page $page): array
    {
        $current = self::current($page);

        return [
            'title' => hash('sha256', $current['title']),
            'content' => hash('sha256', $current['content']),
            'excerpt' => hash('sha256', $current['excerpt']),
        ];
    }
}
