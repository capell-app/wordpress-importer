<?php

declare(strict_types=1);

namespace Capell\WordPressImporter\Actions;

use Capell\MigrationAssistant\Services\Import\ExternalImportPreviewBuilder;
use Capell\WordPressImporter\Data\WordPressImportPreviewData;
use Capell\WordPressImporter\Services\WxrReader;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

/**
 * @method static WordPressImportPreviewData run(string $path)
 */
final class BuildWordPressImportPreviewAction
{
    use AsObject;

    /** @var array<string, string> */
    private const array WXR_FIELD_MAPPING = [
        'source_identity' => 'meta.wordpress.source_identity',
        'post_id' => 'meta.wordpress.post_id',
        'post_type' => 'meta.wordpress.post_type',
        'post_title' => 'name',
        'post_name' => 'meta.slug',
        'old_permalink' => 'meta.wordpress.old_permalink',
        'link' => 'meta.wordpress.link',
        'post_content' => 'meta.content',
        'post_excerpt' => 'meta.excerpt',
        'post_status' => 'meta.status',
        'post_date' => 'visible_from',
        'parent_id' => 'meta.wordpress.parent_id',
        'author_login' => 'meta.wordpress.author_login',
        'categories' => 'meta.wordpress.categories',
        'tags' => 'meta.wordpress.tags',
        'attachments' => 'meta.wordpress.attachments',
        'media_urls' => 'meta.wordpress.media_urls',
        'featured_media_url' => 'meta.wordpress.featured_media_url',
        'contains_gutenberg_blocks' => 'meta.wordpress.contains_gutenberg_blocks',
        'shortcodes' => 'meta.wordpress.shortcodes',
    ];

    /**
     * @return array<string, string>
     */
    public static function fieldMapping(): array
    {
        return self::WXR_FIELD_MAPPING;
    }

    public function handle(string $path): WordPressImportPreviewData
    {
        $resolvedPath = realpath($path);

        if ($resolvedPath === false) {
            throw new RuntimeException((string) __('capell-wordpress-importer::commands.import.path_missing', ['path' => $path]));
        }

        $reader = resolve(WxrReader::class);

        if (! $reader->supportsPath($resolvedPath)) {
            throw new RuntimeException((string) __('capell-wordpress-importer::commands.import.invalid_wxr', ['path' => $path]));
        }

        $readResult = $reader->read($resolvedPath);
        $preview = resolve(ExternalImportPreviewBuilder::class)->build($readResult, self::WXR_FIELD_MAPPING);

        return new WordPressImportPreviewData(
            path: $resolvedPath,
            readResult: $readResult,
            preview: $preview,
        );
    }
}
