<?php

declare(strict_types=1);

namespace Capell\WordPressImporter\Services;

use Capell\MigrationAssistant\Contracts\PathAwareImportSourceReader;
use Capell\MigrationAssistant\Data\ExternalImportReadResult;
use Capell\MigrationAssistant\Support\Xml\SafeXmlLoader;
use RuntimeException;
use SimpleXMLElement;
use Throwable;
use XMLReader as NativeXmlReader;

final class WxrReader implements PathAwareImportSourceReader
{
    /** @var list<string> */
    private const array WXR_COLUMNS = [
        'source_identity',
        'post_id',
        'post_type',
        'post_title',
        'post_name',
        'old_permalink',
        'link',
        'post_content',
        'post_excerpt',
        'post_status',
        'post_date',
        'parent_id',
        'author_login',
        'categories',
        'tags',
        'attachments',
        'media_urls',
        'featured_media_url',
        'contains_gutenberg_blocks',
        'shortcodes',
    ];

    public function supports(string $extension): bool
    {
        return false;
    }

    public function supportsPath(string $path): bool
    {
        if (! is_readable($path) || is_dir($path)) {
            return false;
        }

        return $this->isWordPressExportPath($path);
    }

    public function read(string $path): ExternalImportReadResult
    {
        if (! is_readable($path)) {
            throw new RuntimeException(sprintf('WordPress export [%s] is not readable.', $path));
        }

        if (class_exists(NativeXmlReader::class)) {
            return $this->readStreaming($path);
        }

        return $this->readDom($path);
    }

    private function readDom(string $path): ExternalImportReadResult
    {
        $xml = SafeXmlLoader::loadFile($path, LIBXML_NOCDATA | LIBXML_NONET);

        $channel = $xml->channel;
        throw_if(! $channel instanceof SimpleXMLElement || (! property_exists($channel, 'item') || $channel->item === null), RuntimeException::class, 'WordPress export must contain a channel with item entries.');

        if (! $this->isWordPressExport($channel)) {
            throw new RuntimeException(sprintf('WordPress export [%s] does not contain WXR metadata.', $path));
        }

        $attachmentsByParent = $this->attachmentsByParent($channel);
        $rows = [];
        $itemErrors = [];

        foreach ($channel->item as $itemIndex => $item) {
            $wp = $item->children('wp', true);
            $postType = trim((string) $wp->post_type);

            if (! in_array($postType, ['page', 'post'], true)) {
                continue;
            }

            try {
                $rows[] = $this->rowFromItem($item, $attachmentsByParent);
            } catch (Throwable $throwable) {
                $itemErrors[] = sprintf(
                    'Item %d (%s) skipped: %s',
                    ((int) $itemIndex) + 1,
                    $this->itemLabel($item),
                    $throwable->getMessage(),
                );
            }
        }

        return new ExternalImportReadResult(
            sourceType: 'wordpress-wxr',
            columns: self::WXR_COLUMNS,
            rows: $rows,
            metadata: [
                'filename' => basename($path),
                'site_title' => trim((string) $channel->title),
                'wxr_version' => trim((string) $channel->children('wp', true)->wxr_version),
                'post_count' => count($rows),
                'attachment_count' => array_sum(array_map(count(...), $attachmentsByParent)),
                'skipped_item_count' => count($itemErrors),
                'item_errors' => $itemErrors,
            ],
            suggestedTarget: 'page',
        );
    }

    private function readStreaming(string $path): ExternalImportReadResult
    {
        $metadata = $this->streamMetadata($path);

        if ($metadata['wxr_version'] === '') {
            throw new RuntimeException(sprintf('WordPress export [%s] does not contain WXR metadata.', $path));
        }

        $attachmentsByParent = $this->streamAttachmentsByParent($path);
        $rows = [];
        $itemErrors = [];
        $itemIndex = 0;

        $this->withXmlReader($path, function (NativeXmlReader $reader) use (&$rows, &$itemErrors, &$itemIndex, $attachmentsByParent): void {
            while ($reader->read()) {
                $this->rejectDoctype($reader);
                if ($reader->nodeType !== NativeXmlReader::ELEMENT) {
                    continue;
                }

                if ($reader->localName !== 'item') {
                    continue;
                }

                $itemIndex++;
                $item = $this->simpleXmlFromCurrentItem($reader);
                $postType = trim((string) $item->children('wp', true)->post_type);

                if (! in_array($postType, ['page', 'post'], true)) {
                    continue;
                }

                try {
                    $rows[] = $this->rowFromItem($item, $attachmentsByParent);
                } catch (Throwable $throwable) {
                    $itemErrors[] = sprintf(
                        'Item %d (%s) skipped: %s',
                        $itemIndex,
                        $this->itemLabel($item),
                        $throwable->getMessage(),
                    );
                }
            }
        });

        throw_if($itemIndex === 0, RuntimeException::class, 'WordPress export must contain a channel with item entries.');

        return new ExternalImportReadResult(
            sourceType: 'wordpress-wxr',
            columns: self::WXR_COLUMNS,
            rows: $rows,
            metadata: [
                'filename' => basename($path),
                'site_title' => $metadata['site_title'],
                'wxr_version' => $metadata['wxr_version'],
                'post_count' => count($rows),
                'attachment_count' => array_sum(array_map(count(...), $attachmentsByParent)),
                'skipped_item_count' => count($itemErrors),
                'item_errors' => $itemErrors,
            ],
            suggestedTarget: 'page',
        );
    }

    private function isWordPressExport(mixed $channel): bool
    {
        if (! $channel instanceof SimpleXMLElement) {
            return false;
        }

        $namespaces = $channel->getNamespaces(true);

        if (! array_key_exists('wp', $namespaces)) {
            return false;
        }

        return trim((string) $channel->children('wp', true)->wxr_version) !== '';
    }

    private function isWordPressExportPath(string $path): bool
    {
        if (class_exists(NativeXmlReader::class)) {
            return $this->streamHasWxrVersion($path);
        }

        try {
            return $this->isWordPressExport(SafeXmlLoader::loadFile($path, LIBXML_NOCDATA | LIBXML_NONET)->channel);
        } catch (Throwable) {
            return false;
        }
    }

    private function streamHasWxrVersion(string $path): bool
    {
        $reader = new NativeXmlReader;
        $previousXmlErrorHandling = libxml_use_internal_errors(true);

        try {
            if (! $reader->open($path, null, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                return false;
            }

            while ($reader->read()) {
                if ($reader->nodeType === NativeXmlReader::DOC_TYPE) {
                    return false;
                }

                if (
                    $reader->nodeType === NativeXmlReader::ELEMENT
                    && $reader->localName === 'wxr_version'
                    && str_starts_with($reader->namespaceURI, 'http://wordpress.org/export/')
                    && trim($reader->readString()) !== ''
                ) {
                    return true;
                }
            }

            return false;
        } catch (Throwable) {
            return false;
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previousXmlErrorHandling);
        }
    }

    /**
     * @return array{site_title: string, wxr_version: string}
     */
    private function streamMetadata(string $path): array
    {
        $metadata = [
            'site_title' => '',
            'wxr_version' => '',
        ];
        $insideChannel = false;

        $this->withXmlReader($path, function (NativeXmlReader $reader) use (&$metadata, &$insideChannel): void {
            while ($reader->read()) {
                $this->rejectDoctype($reader);

                if ($reader->nodeType === NativeXmlReader::ELEMENT && $reader->localName === 'channel') {
                    $insideChannel = true;

                    continue;
                }

                if ($reader->nodeType === NativeXmlReader::END_ELEMENT && $reader->localName === 'channel') {
                    return;
                }

                if (! $insideChannel) {
                    continue;
                }

                if ($reader->nodeType !== NativeXmlReader::ELEMENT) {
                    continue;
                }

                if ($reader->localName === 'title' && $metadata['site_title'] === '') {
                    $metadata['site_title'] = trim($reader->readString());

                    continue;
                }

                if (
                    $reader->localName === 'wxr_version'
                    && str_starts_with($reader->namespaceURI, 'http://wordpress.org/export/')
                ) {
                    $metadata['wxr_version'] = trim($reader->readString());
                }
            }
        });

        return $metadata;
    }

    /**
     * @return array<string, list<array{url: string, title: string}>>
     */
    private function streamAttachmentsByParent(string $path): array
    {
        $attachments = [];

        $this->withXmlReader($path, function (NativeXmlReader $reader) use (&$attachments): void {
            while ($reader->read()) {
                $this->rejectDoctype($reader);
                if ($reader->nodeType !== NativeXmlReader::ELEMENT) {
                    continue;
                }

                if ($reader->localName !== 'item') {
                    continue;
                }

                $item = $this->simpleXmlFromCurrentItem($reader);
                $wp = $item->children('wp', true);

                if (trim((string) $wp->post_type) !== 'attachment') {
                    continue;
                }

                $parentId = trim((string) $wp->post_parent);
                $url = trim((string) $wp->attachment_url);
                if ($parentId === '') {
                    continue;
                }

                if ($parentId === '0') {
                    continue;
                }

                if ($url === '') {
                    continue;
                }

                $attachments[$parentId] ??= [];
                $attachments[$parentId][] = [
                    'url' => $url,
                    'title' => trim((string) $item->title),
                ];
            }
        });

        return $attachments;
    }

    private function simpleXmlFromCurrentItem(NativeXmlReader $reader): SimpleXMLElement
    {
        $outerXml = $reader->readOuterXml();

        throw_if(! is_string($outerXml) || trim($outerXml) === '', RuntimeException::class, 'empty WXR item XML');

        return SafeXmlLoader::loadString($outerXml, LIBXML_NOCDATA | LIBXML_NONET, PHP_INT_MAX);
    }

    private function rejectDoctype(NativeXmlReader $reader): void
    {
        throw_if($reader->nodeType === NativeXmlReader::DOC_TYPE, RuntimeException::class, 'XML payload contains a DOCTYPE declaration which is not permitted.');
    }

    private function withXmlReader(string $path, callable $callback): void
    {
        $reader = new NativeXmlReader;
        $previousXmlErrorHandling = libxml_use_internal_errors(true);

        try {
            if (! $reader->open($path, null, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                throw new RuntimeException(sprintf('WordPress export [%s] could not be opened for streaming.', $path));
            }

            $callback($reader);
        } catch (Throwable $throwable) {
            throw $throwable instanceof RuntimeException
                ? $throwable
                : new RuntimeException('WordPress export could not be streamed safely: ' . $throwable->getMessage(), 0, $throwable);
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previousXmlErrorHandling);
        }
    }

    /**
     * @param  array<string, list<array{url: string, title: string}>>  $attachmentsByParent
     * @return array<string, mixed>
     */
    private function rowFromItem(SimpleXMLElement $item, array $attachmentsByParent): array
    {
        $wp = $item->children('wp', true);
        $content = $item->children('content', true);
        $dc = $item->children('dc', true);
        $excerpt = $item->children('excerpt', true);
        $postId = trim((string) $wp->post_id);
        $postTitle = trim((string) $item->title);

        $this->assertImportableItem($postId, $postTitle);

        $postContent = trim((string) $content->encoded);
        $attachments = array_values(array_merge(
            $this->inlineAttachments($wp, $postTitle),
            $attachmentsByParent[$postId] ?? [],
        ));
        $mediaUrls = array_values(array_unique(array_map(
            static fn (array $attachment): string => $attachment['url'],
            $attachments,
        )));

        return [
            'source_identity' => 'wordpress:' . $postId,
            'post_id' => $postId,
            'post_type' => trim((string) $wp->post_type),
            'post_title' => $postTitle,
            'post_name' => trim((string) $wp->post_name),
            'old_permalink' => trim((string) $item->link),
            'link' => trim((string) $item->link),
            'post_content' => $postContent,
            'post_excerpt' => trim((string) $excerpt->encoded),
            'post_status' => trim((string) $wp->status),
            'post_date' => trim((string) $wp->post_date),
            'parent_id' => trim((string) $wp->post_parent),
            'author_login' => trim((string) $dc->creator),
            'categories' => $this->terms($item, 'category'),
            'tags' => $this->terms($item, 'post_tag'),
            'attachments' => $attachments,
            'media_urls' => $mediaUrls,
            'featured_media_url' => $mediaUrls[0] ?? null,
            'contains_gutenberg_blocks' => str_contains($postContent, '<!-- wp:'),
            'shortcodes' => $this->shortcodes($postContent),
        ];
    }

    private function assertImportableItem(string $postId, string $postTitle): void
    {
        throw_if($postId === '', RuntimeException::class, 'missing wp:post_id');

        throw_if($postTitle === '', RuntimeException::class, 'missing title');
    }

    private function itemLabel(SimpleXMLElement $item): string
    {
        $wp = $item->children('wp', true);
        $postId = trim((string) $wp->post_id);
        $title = trim((string) $item->title);

        if ($postId !== '') {
            return 'wp:post_id=' . $postId;
        }

        if ($title !== '') {
            return $title;
        }

        return 'untitled';
    }

    /**
     * @return list<string>
     */
    private function terms(SimpleXMLElement $item, string $domain): array
    {
        $terms = [];

        foreach ($item->category as $category) {
            $attributes = $category->attributes();
            if ((string) ($attributes['domain'] ?? '') !== $domain) {
                continue;
            }

            $terms[] = trim((string) $category);
        }

        return array_values(array_filter(
            $terms,
            static fn (string $term): bool => $term !== '',
        ));
    }

    /**
     * @return list<array{url: string, title: string}>
     */
    private function inlineAttachments(SimpleXMLElement $wp, string $title): array
    {
        $url = trim((string) $wp->attachment_url);
        if ($url === '') {
            return [];
        }

        return [[
            'url' => $url,
            'title' => $title,
        ]];
    }

    /**
     * @return array<string, list<array{url: string, title: string}>>
     */
    private function attachmentsByParent(SimpleXMLElement $channel): array
    {
        $attachments = [];

        foreach ($channel->item as $item) {
            $wp = $item->children('wp', true);

            if (trim((string) $wp->post_type) !== 'attachment') {
                continue;
            }

            $parentId = trim((string) $wp->post_parent);
            $attachment = $this->inlineAttachments($wp, trim((string) $item->title));
            if ($parentId === '') {
                continue;
            }

            if ($attachment === []) {
                continue;
            }

            $attachments[$parentId] = array_values(array_merge($attachments[$parentId] ?? [], $attachment));
        }

        return $attachments;
    }

    /**
     * @return list<string>
     */
    private function shortcodes(string $content): array
    {
        preg_match_all('/\[(?!\/)([a-zA-Z][a-zA-Z0-9_-]*)\b[^\]]*\]/', $content, $matches);

        return array_values(array_unique($matches[1]));
    }
}
