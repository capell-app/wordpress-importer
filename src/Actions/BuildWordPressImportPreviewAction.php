<?php

declare(strict_types=1);

namespace Capell\WordPressImporter\Actions;

use Capell\MigrationAssistant\Services\Import\ExternalImportPreviewBuilder;
use Capell\WordPressImporter\Data\WordPressImportPreviewData;
use Lorisleiva\Actions\Concerns\AsObject;

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
        'post_content_raw' => 'meta.wordpress.raw_content',
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
        $wxr = ReadWordPressWxrAction::run($path);
        $preview = ApplyWordPressPreviewIdempotencyAction::run(
            resolve(ExternalImportPreviewBuilder::class)->build($wxr->readResult, self::WXR_FIELD_MAPPING),
        );

        return new WordPressImportPreviewData(
            path: $wxr->path,
            readResult: $wxr->readResult,
            preview: $preview,
        );
    }
}
