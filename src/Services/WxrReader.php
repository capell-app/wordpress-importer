<?php

declare(strict_types=1);

namespace Capell\WordPressImporter\Services;

use Capell\Core\Support\Security\PublicHtmlSanitizer;
use Capell\MigrationAssistant\Contracts\PathAwareImportSourceReader;
use Capell\MigrationAssistant\Data\ExternalImportReadResult;
use Capell\MigrationAssistant\Support\Xml\SafeXmlLoader;
use Capell\WordPressImporter\Actions\ConvertWordPressGutenbergBlocksAction;
use Capell\WordPressImporter\Support\WordPressAttachmentIndex;
use Closure;
use Illuminate\Support\Str;
use Override;
use RuntimeException;
use SimpleXMLElement;
use Throwable;
use XMLReader as NativeXmlReader;

/**
 * Registry adapter for Migration Assistant's source-reader contract.
 *
 * ReadWordPressWxrAction owns path resolution and import orchestration; this
 * service stays focused on the streaming and DOM WXR parsing implementation
 * required by the shared reader registry.
 */
final class WxrReader implements PathAwareImportSourceReader
{
    private const int MARKUP_COMMENT_STRIP_PASSES = 16;

    /** @var list<string> */
    private const array WXR_COLUMNS = [
        'source_identity',
        'source_key',
        'post_id',
        'post_type',
        'post_title',
        'post_name',
        'old_permalink',
        'link',
        'post_content',
        'post_content_raw',
        'post_excerpt',
        'post_status',
        'password_protected',
        'post_date',
        'parent_id',
        'author_login',
        'author_email',
        'author_name',
        'categories',
        'tags',
        'term_slugs',
        'unsupported_blocks',
        'field_hashes',
        'attachments',
        'media_urls',
        'featured_media_url',
        'thumbnail_media_url',
        'contains_gutenberg_blocks',
        'shortcodes',
    ];

    #[Override]
    public function supports(string $extension): bool
    {
        return false;
    }

    #[Override]
    public function supportsPath(string $path): bool
    {
        if (! is_readable($path) || is_dir($path)) {
            return false;
        }

        return $this->isWordPressExportPath($path);
    }

    #[Override]
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

    /**
     * @param  Closure(array<string, mixed>, int): void  $onRow
     * @return array{filename: string, site_title: string, wxr_version: string, attachment_count: int, skipped_item_count: int, item_errors: list<string>}
     */
    public function streamRows(string $path, Closure $onRow): array
    {
        throw_unless(class_exists(NativeXmlReader::class), RuntimeException::class, 'Streaming WordPress imports require XMLReader.');
        throw_unless(is_readable($path) && ! is_dir($path), RuntimeException::class, sprintf('WordPress export [%s] is not readable.', $path));

        $metadata = $this->streamMetadata($path);

        if ($metadata['wxr_version'] === '') {
            throw new RuntimeException(sprintf('WordPress export [%s] does not contain WXR metadata.', $path));
        }

        $sourceKey = $this->sourceKey($path, $metadata['base_blog_url'] ?: $metadata['base_site_url']);
        $attachmentIndex = $this->streamAttachmentIndex($path);
        $itemErrors = [];
        $itemIndex = 0;
        $rowIndex = 0;
        $skippedItemCount = 0;

        try {
            $this->withXmlReader($path, function (NativeXmlReader $reader) use (&$itemErrors, &$itemIndex, &$rowIndex, &$skippedItemCount, $attachmentIndex, $onRow, $sourceKey, $metadata): void {
                while ($reader->read()) {
                    $this->rejectDoctype($reader);

                    if ($reader->nodeType !== NativeXmlReader::ELEMENT || $reader->localName !== 'item') {
                        continue;
                    }

                    $itemIndex++;
                    $item = $this->simpleXmlFromCurrentItem($reader);
                    $wp = $item->children('wp', true);
                    $postType = trim((string) $wp->post_type);

                    if (! in_array($postType, ['page', 'post'], true)) {
                        continue;
                    }

                    try {
                        $row = $this->rowFromItem(
                            $item,
                            $attachmentIndex->forParent(trim((string) $wp->post_id)),
                            $sourceKey,
                            $metadata['authors'],
                            $attachmentIndex->urlForAttachment(...),
                        );
                    } catch (Throwable $throwable) {
                        $skippedItemCount++;

                        if (count($itemErrors) < $this->maximumStoredItemErrors()) {
                            $itemErrors[] = $this->itemError($itemIndex, $throwable);
                        }

                        continue;
                    }

                    $rowIndex++;
                    $onRow($row, $rowIndex);
                }
            });

            throw_if($itemIndex === 0, RuntimeException::class, 'WordPress export must contain a channel with item entries.');

            return [
                'filename' => basename($path),
                'site_title' => $metadata['site_title'],
                'wxr_version' => $metadata['wxr_version'],
                'attachment_count' => $attachmentIndex->count(),
                'skipped_item_count' => $skippedItemCount,
                'item_errors' => $itemErrors,
            ];
        } finally {
            $attachmentIndex->close();
        }
    }

    private function readDom(string $path): ExternalImportReadResult
    {
        $xml = SafeXmlLoader::loadFile($path, LIBXML_NOCDATA | LIBXML_NONET);

        $channel = $xml->channel;
        throw_if(! $channel instanceof SimpleXMLElement || (! property_exists($channel, 'item') || $channel->item === null), RuntimeException::class, 'WordPress export must contain a channel with item entries.');

        if (! $this->isWordPressExport($channel)) {
            throw new RuntimeException(sprintf('WordPress export [%s] does not contain WXR metadata.', $path));
        }

        $source = $channel->children('wp', true);
        $sourceKey = $this->sourceKey($path, trim((string) $source->base_blog_url) ?: trim((string) $source->base_site_url));
        $attachmentsByParent = $this->attachmentsByParent($channel);
        $attachmentUrls = $this->attachmentUrlsById($channel);
        $authors = [];
        foreach ($source->author as $authorElement) {
            $author = $this->authorFromElement($authorElement);
            if ($author !== null) {
                $authors[$author['login']] = $author;
            }
        }
        $rows = [];
        $itemErrors = [];
        $itemIndex = 0;
        $skippedItemCount = 0;

        foreach ($channel->item as $item) {
            $itemIndex++;
            $wp = $item->children('wp', true);
            $postType = trim((string) $wp->post_type);

            if (! in_array($postType, ['page', 'post'], true)) {
                continue;
            }

            try {
                $postId = trim((string) $wp->post_id);
                $rows[] = $this->rowFromItem(
                    $item,
                    $attachmentsByParent[$postId] ?? [],
                    $sourceKey,
                    $authors,
                    static fn (string $attachmentId): ?string => $attachmentUrls[$attachmentId] ?? null,
                );
            } catch (Throwable $throwable) {
                $skippedItemCount++;
                if (count($itemErrors) < $this->maximumStoredItemErrors()) {
                    $itemErrors[] = $this->itemError($itemIndex, $throwable);
                }
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
                'skipped_item_count' => $skippedItemCount,
                'item_errors' => $itemErrors,
            ],
            suggestedTarget: 'page',
        );
    }

    private function readStreaming(string $path): ExternalImportReadResult
    {
        $rows = [];
        $metadata = $this->streamRows($path, static function (array $row) use (&$rows): void {
            $rows[] = $row;
        });

        return new ExternalImportReadResult(
            sourceType: 'wordpress-wxr',
            columns: self::WXR_COLUMNS,
            rows: $rows,
            metadata: [
                ...$metadata,
                'post_count' => count($rows),
            ],
            suggestedTarget: 'page',
        );
    }

    private function maximumStoredItemErrors(): int
    {
        $configured = config('wordpress-importer.spool.max_stored_item_errors', 100);

        return is_numeric($configured) ? max(1, (int) $configured) : 100;
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
     * @return array{site_title: string, wxr_version: string, base_blog_url: string, base_site_url: string, authors: array<string, array{login: string, email: string, name: string}>}
     */
    private function streamMetadata(string $path): array
    {
        $metadata = [
            'site_title' => '',
            'wxr_version' => '',
            'base_blog_url' => '',
            'base_site_url' => '',
            'authors' => [],
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

                if ($reader->depth === 2 && $reader->localName === 'author' && str_starts_with($reader->namespaceURI, 'http://wordpress.org/export/')) {
                    $wrapper = SafeXmlLoader::loadString('<wrapper xmlns:wp="' . htmlspecialchars($reader->namespaceURI, ENT_QUOTES | ENT_XML1) . '">' . $reader->readOuterXml() . '</wrapper>', LIBXML_NOCDATA | LIBXML_NONET);
                    $author = $this->authorFromElement($wrapper->children('wp', true)->author);
                    if ($author !== null) {
                        $metadata['authors'][$author['login']] = $author;
                    }

                    continue;
                }

                if ($reader->depth === 2 && in_array($reader->localName, ['base_blog_url', 'base_site_url'], true) && str_starts_with($reader->namespaceURI, 'http://wordpress.org/export/')) {
                    $metadata[$reader->localName] = trim($reader->readString());

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

    private function streamAttachmentIndex(string $path): WordPressAttachmentIndex
    {
        $index = new WordPressAttachmentIndex(
            maximumAttachments: $this->maximumIndexedAttachments(),
            maximumAttachmentsPerParent: $this->maximumAttachmentsPerParent(),
            maximumBytes: $this->maximumAttachmentIndexBytes(),
        );

        try {
            $this->withXmlReader($path, function (NativeXmlReader $reader) use ($index): void {
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
                    $attachmentId = trim((string) $wp->post_id);

                    if ($attachmentId !== '' && $url !== '') {
                        $index->addAttachmentUrl($attachmentId, $url);
                    }

                    if ($parentId === '' || $parentId === '0' || $url === '') {
                        continue;
                    }

                    $index->add($parentId, $url, trim((string) $item->title));
                }
            });
        } catch (Throwable $throwable) {
            $index->close();

            throw $throwable;
        }

        return $index;
    }

    private function simpleXmlFromCurrentItem(NativeXmlReader $reader): SimpleXMLElement
    {
        $outerXml = $reader->readOuterXml();

        throw_if(! is_string($outerXml) || trim($outerXml) === '', RuntimeException::class, 'empty WXR item XML');

        // Enforce a per-item byte ceiling so a single enormous <item> (e.g. a huge
        // content:encoded body) cannot OOM-kill the streaming import worker. Passing
        // PHP_INT_MAX would defeat the loader's size guard entirely; the loader's
        // default 50MB cap is restored here and an oversized item throws a catchable
        // RuntimeException instead of exhausting memory.
        $maximumItemBytes = SafeXmlLoader::DEFAULT_MAX_BYTES;

        return SafeXmlLoader::loadString($outerXml, LIBXML_NOCDATA | LIBXML_NONET, $maximumItemBytes);
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

    private function sanitiseRawContent(string $content, PublicHtmlSanitizer $sanitizer): string
    {
        $content = $this->stripUnsafeMarkupComments($content, keepBlockComments: true);
        $comments = [];
        $prefix = 'CAPELLWXR' . bin2hex(random_bytes(16));
        $masked = preg_replace_callback('/<!--\s*\/?wp:[^<>]*-->/', static function (array $match) use (&$comments, $prefix): string {
            $key = $prefix . count($comments) . 'END';
            $comments[$key] = $match[0];

            return $key;
        }, $content);

        // Only inert, well-formed block delimiters return to the metadata copy. Content remains sanitised.
        return strtr($sanitizer->sanitize($masked ?? ''), $comments);
    }

    private function sourceKey(string $path, string $sourceUrl): string
    {
        $parts = parse_url($sourceUrl);
        if (is_array($parts) && is_string($parts['host'] ?? null) && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            $port = isset($parts['port']) && ! in_array($parts['port'], [80, 443], true) ? ':' . $parts['port'] : '';
            $identity = strtolower($parts['host']) . $port . '/' . trim($parts['path'] ?? '', '/');

            return hash('sha256', $identity);
        }

        // Old exports may omit the source URL. Only an exact repeat can then be recognised safely.
        $hash = hash_file('sha256', $path);
        throw_unless(is_string($hash), RuntimeException::class, 'Unable to identify WordPress source.');

        return $hash;
    }

    /**
     * @param  list<array{url: string, title: string}>  $parentAttachments
     * @param  array<string, array{login: string, email: string, name: string}>  $authors
     * @param  (Closure(string): ?string)|null  $attachmentUrl  finds an attachment's file by its post id
     * @return array<string, mixed>
     */
    private function rowFromItem(SimpleXMLElement $item, array $parentAttachments, string $sourceKey, array $authors = [], ?Closure $attachmentUrl = null): array
    {
        $wp = $item->children('wp', true);
        $content = $item->children('content', true);
        $dc = $item->children('dc', true);
        $excerpt = $item->children('excerpt', true);
        $postId = trim((string) $wp->post_id);
        $postTitle = trim((string) $item->title);

        $this->assertImportableItem($postId, $postTitle);

        // Export HTML is untrusted: scripts, event handlers and unsafe URLs never reach stored page content.
        $sanitizer = resolve(PublicHtmlSanitizer::class);
        $exportedContent = trim((string) $content->encoded);
        $postContentRaw = $this->sanitiseRawContent($exportedContent, $sanitizer);
        $conversion = ConvertWordPressGutenbergBlocksAction::run($exportedContent);
        $postContent = $sanitizer->sanitize($this->stripUnsafeMarkupComments($this->normalizeContent($conversion->html), keepBlockComments: false));
        $postExcerpt = $sanitizer->sanitize($this->stripUnsafeMarkupComments(trim((string) $excerpt->encoded), keepBlockComments: false));
        $authorLogin = trim((string) $dc->creator);
        $author = $authors[$authorLogin] ?? null;
        // A password-protected post is not public on the source site, so it never imports as published.
        // The password itself is never read into the import.
        $passwordProtected = trim((string) $wp->post_password) !== '';
        $attachments = array_values(array_merge(
            $this->inlineAttachments($wp, $postTitle),
            $parentAttachments,
        ));
        $mediaUrls = array_values(array_unique(array_map(
            static fn (array $attachment): string => $attachment['url'],
            $attachments,
        )));
        $thumbnailMediaUrl = $this->thumbnailMediaUrl($wp, $attachmentUrl);

        return [
            'source_identity' => 'wordpress:' . $postId,
            'source_key' => $sourceKey,
            'post_id' => $postId,
            'post_type' => trim((string) $wp->post_type),
            'post_title' => $postTitle,
            'post_name' => trim((string) $wp->post_name),
            'old_permalink' => trim((string) $item->link),
            'link' => trim((string) $item->link),
            'post_content' => $postContent,
            'post_content_raw' => $postContentRaw,
            'post_excerpt' => $postExcerpt,
            'post_status' => $passwordProtected ? 'private' : trim((string) $wp->status),
            'password_protected' => $passwordProtected,
            'post_date' => trim((string) $wp->post_date),
            'parent_id' => trim((string) $wp->post_parent),
            'author_login' => $authorLogin,
            'author_email' => is_array($author) ? $author['email'] : '',
            'author_name' => is_array($author) ? $author['name'] : '',
            'categories' => $this->terms($item, 'category'),
            'tags' => $this->terms($item, 'post_tag'),
            'term_slugs' => [
                'category' => $this->termDetails($item, 'category'),
                'post_tag' => $this->termDetails($item, 'post_tag'),
            ],
            'unsupported_blocks' => $conversion->unsupportedBlocks,
            // Hashes of the values as imported let a later re-import tell an editor's change from an unchanged field.
            'field_hashes' => [
                'title' => hash('sha256', $postTitle),
                'content' => hash('sha256', $postContent),
                'excerpt' => hash('sha256', $postExcerpt),
            ],
            'attachments' => $attachments,
            'media_urls' => $mediaUrls,
            'featured_media_url' => $thumbnailMediaUrl ?? $mediaUrls[0] ?? null,
            'thumbnail_media_url' => $thumbnailMediaUrl,
            'contains_gutenberg_blocks' => str_contains($exportedContent, '<!-- wp:'),
            'shortcodes' => $this->shortcodes($exportedContent),
        ];
    }

    /**
     * The file of the attachment a post names as its featured image. A post without one has none: the first image in
     * its body is not a featured image.
     *
     * @param  (Closure(string): ?string)|null  $attachmentUrl
     */
    private function thumbnailMediaUrl(SimpleXMLElement $wp, ?Closure $attachmentUrl): ?string
    {
        if (! $attachmentUrl instanceof Closure) {
            return null;
        }

        foreach ($wp->postmeta as $postmeta) {
            if (trim((string) $postmeta->meta_key) !== '_thumbnail_id') {
                continue;
            }

            $attachmentId = trim((string) $postmeta->meta_value);

            return $attachmentId === '' ? null : $attachmentUrl($attachmentId);
        }

        return null;
    }

    /** @return array<string, string> */
    private function attachmentUrlsById(SimpleXMLElement $channel): array
    {
        $urls = [];

        foreach ($channel->item as $item) {
            $wp = $item->children('wp', true);
            $attachmentId = trim((string) $wp->post_id);
            $url = trim((string) $wp->attachment_url);

            if (trim((string) $wp->post_type) === 'attachment' && $attachmentId !== '' && $url !== '') {
                $urls[$attachmentId] = $url;
            }
        }

        return $urls;
    }

    private function assertImportableItem(string $postId, string $postTitle): void
    {
        throw_if($postId === '', RuntimeException::class, 'missing wp:post_id');

        throw_if($postTitle === '', RuntimeException::class, 'missing title');
    }

    private function itemError(int $itemIndex, Throwable $throwable): string
    {
        $reason = match ($throwable->getMessage()) {
            'missing wp:post_id' => __('capell-wordpress-importer::commands.import.missing_id'),
            'missing title' => __('capell-wordpress-importer::commands.import.missing_title'),
            default => __('capell-wordpress-importer::commands.import.invalid_item'),
        };

        return (string) __('capell-wordpress-importer::commands.import.item_omitted', ['item' => $itemIndex, 'reason' => $reason]);
    }

    /**
     * @return array{login: string, email: string, name: string}|null
     */
    private function authorFromElement(SimpleXMLElement $element): ?array
    {
        $wp = $element->children('wp', true);
        $login = trim((string) $wp->author_login);

        if ($login === '') {
            return null;
        }

        return [
            'login' => $login,
            'email' => mb_strtolower(trim((string) $wp->author_email)),
            'name' => trim((string) $wp->author_display_name),
        ];
    }

    /**
     * @return list<array{name: string, slug: string}>
     */
    private function termDetails(SimpleXMLElement $item, string $domain): array
    {
        $terms = [];

        foreach ($item->category as $category) {
            $attributes = $category->attributes();
            if ((string) ($attributes['domain'] ?? '') !== $domain) {
                continue;
            }

            $name = trim((string) $category);
            $slug = Str::slug(trim((string) ($attributes['nicename'] ?? '')) ?: $name);
            if ($name !== '' && $slug !== '') {
                $terms[] = ['name' => $name, 'slug' => $slug];
            }
        }

        return $terms;
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

    /**
     * Comments, processing instructions and other declarations are removed before sanitising: parsers
     * disagree about where malformed comments such as `<!-->` end, so a sanitiser can keep as a comment
     * markup that a browser renders. Well-formed block delimiters are kept in the raw source copy.
     * Removing one construct can join its neighbours into a new one, so passes repeat until nothing
     * changes, and content that never settles is dropped.
     */
    private function stripUnsafeMarkupComments(string $html, bool $keepBlockComments): string
    {
        for ($pass = 0; $pass < self::MARKUP_COMMENT_STRIP_PASSES; $pass++) {
            $stripped = preg_replace_callback(
                '/<!--.*?(?:-->|\z)/s',
                static fn (array $matches): string => $keepBlockComments && preg_match('/\A<!--\s*\/?wp:[^>]*-->\z/', $matches[0]) === 1 ? $matches[0] : '',
                $html,
            );
            $stripped = is_string($stripped) ? preg_replace('/<\?.*?(?:>|\z)/s', '', $stripped) : null;
            $stripped = is_string($stripped) ? preg_replace('/<!(?!--)[^>]*(?:>|\z)/', '', $stripped) : null;

            if (! is_string($stripped)) {
                // A PCRE failure (backtrack or JIT limit) drops the content rather than keep unsafe markup; the
                // reason is logged so an empty imported body can be traced. The content itself is never logged.
                logger()->warning('WordPress import dropped content whose markup comments could not be removed.', ['pcre_error' => preg_last_error_msg(), 'bytes' => strlen($html)]);

                return '';
            }

            if ($stripped === $html) {
                return $stripped;
            }

            $html = $stripped;
        }

        return '';
    }

    private function normalizeContent(string $content): string
    {
        $content = preg_replace('/<!--\s*\/?wp:[^>]*-->/', '', $content) ?? $content;

        $content = preg_replace_callback(
            '/\[([a-zA-Z][a-zA-Z0-9_-]*)\b([^\]]*)\](.*?)\[\/\1\]|\[([a-zA-Z][a-zA-Z0-9_-]*)\b([^\]]*)\/?\]/s',
            function (array $matches): string {
                $openingShortcode = $matches[1];
                $enclosedContent = (string) $matches[3];

                if ($openingShortcode === '') {
                    return '';
                }

                $plainText = html_entity_decode(strip_tags($enclosedContent), ENT_QUOTES | ENT_HTML5, 'UTF-8');

                return htmlspecialchars(trim($plainText), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
            },
            $content,
        ) ?? $content;

        return trim($content);
    }

    private function maximumIndexedAttachments(): int
    {
        $configured = config('wordpress-importer.spool.max_indexed_attachments', 100000);

        return is_numeric($configured) ? max(1, (int) $configured) : 100000;
    }

    private function maximumAttachmentsPerParent(): int
    {
        $configured = config('wordpress-importer.spool.max_attachments_per_parent', 1000);

        return is_numeric($configured) ? max(1, (int) $configured) : 1000;
    }

    private function maximumAttachmentIndexBytes(): int
    {
        $configured = config('wordpress-importer.spool.max_attachment_index_bytes', 100 * 1024 * 1024);

        return is_numeric($configured) ? max(1, (int) $configured) : 100 * 1024 * 1024;
    }
}
