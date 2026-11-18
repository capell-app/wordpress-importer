<?php

declare(strict_types=1);

namespace Capell\WordPressImporter\Actions;

use Capell\Core\Contracts\Media\MediaContract;
use Capell\Core\Enums\MediaCollectionEnum;
use Capell\Core\Exceptions\OutboundRequestRefusedException;
use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Capell\Core\Support\Http\OutboundHttp;
use Capell\Core\Support\Http\OutboundHttpPolicy;
use Capell\MigrationAssistant\Actions\StoreImportedMediaAction;
use Capell\MigrationAssistant\Models\ImportSession;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;
use Throwable;

/**
 * @method static int run(iterable<array-key, mixed> $pages, ?ImportSession $session = null)
 */
final class ImportWordPressMediaForPagesAction
{
    use AsFake;
    use AsObject;

    private const string COLLECTION = 'wordpress-import';

    /**
     * @param  iterable<array-key, mixed>  $pages
     */
    public function handle(iterable $pages, ?ImportSession $session = null): int
    {
        $imported = 0;
        $failedUrls = [];

        foreach ($pages as $page) {
            if (! $page instanceof Page) {
                continue;
            }

            $imported += $this->importForPage($page, $session, $failedUrls);
        }

        if ($session instanceof ImportSession && $failedUrls !== []) {
            throw new RuntimeException(sprintf(
                'WordPress media import has %d pending download(s); retry the import session to resume.',
                count($failedUrls),
            ));
        }

        return $imported;
    }

    /** @param list<string> $failedUrls */
    private function importForPage(Page $page, ?ImportSession $session, array &$failedUrls): int
    {
        $meta = is_array($page->getAttribute('meta')) ? $page->getAttribute('meta') : [];
        $wordpress = is_array($meta['wordpress'] ?? null) ? $meta['wordpress'] : [];
        $mediaUrls = $this->mediaUrls($wordpress);

        if ($mediaUrls === []) {
            return 0;
        }

        $content = is_string($meta['content'] ?? null) ? $meta['content'] : null;
        $importedMedia = array_values(array_filter(
            Arr::wrap($wordpress['imported_media'] ?? []),
            static fn (mixed $entry): bool => is_array($entry)
                && is_string($entry['source_url'] ?? null)
                && is_string($entry['media_url'] ?? null),
        ));
        $importedSourceUrls = array_column($importedMedia, 'source_url');
        $newlyImported = 0;

        foreach ($mediaUrls as $mediaUrl) {
            if (in_array($mediaUrl, $importedSourceUrls, true)) {
                $this->recordMediaCheckpoint($session, $page, $mediaUrl, successful: true, newlyImported: false);

                continue;
            }

            $collection = $this->collectionFor($page, $mediaUrl, $wordpress);
            $media = $this->downloadAndAttach($page, $mediaUrl, $collection);

            if (! $media instanceof MediaContract) {
                $failedUrls[] = $mediaUrl;
                $this->recordMediaCheckpoint($session, $page, $mediaUrl, successful: false, newlyImported: false);

                continue;
            }

            // The same file already imported from the post body is reused rather than stored twice, so it is copied
            // into the page image instead of being moved out of the body it still illustrates.
            if ($collection === MediaCollectionEnum::Image->value && $media instanceof Media && $media->collection_name !== $collection) {
                $media->copy($page, $collection);
            }

            $localUrl = $media->getFullUrl();
            $importedMedia[] = [
                'source_url' => $mediaUrl,
                'media_url' => $localUrl,
            ];
            $importedSourceUrls[] = $mediaUrl;
            $newlyImported++;

            if ($content !== null && $localUrl !== '') {
                $content = str_replace($mediaUrl, $localUrl, $content);
            }

            if ($content !== null) {
                $meta['content'] = $content;
            }

            $meta['wordpress'] = array_replace($wordpress, [
                'imported_media' => $importedMedia,
            ]);

            $page->forceFill(['meta' => $meta])->save();
            $this->recordMediaCheckpoint($session, $page, $mediaUrl, successful: true, newlyImported: true);
        }

        if (count($importedSourceUrls) === count($mediaUrls)) {
            $this->recordCompletedPageCheckpoint($session, $page);
        }

        return $newlyImported;
    }

    /**
     * A post's featured image becomes the page's image, unless the page already has one an editor chose. Every
     * other file stays with the import.
     *
     * @param  array<string, mixed>  $wordpress
     */
    private function collectionFor(Page $page, string $mediaUrl, array $wordpress): string
    {
        if (($wordpress['thumbnail_media_url'] ?? null) === $mediaUrl && ! $page->image()->exists()) {
            return MediaCollectionEnum::Image->value;
        }

        return self::COLLECTION;
    }

    /**
     * @param  array<string, mixed>  $wordpress
     * @return list<string>
     */
    private function mediaUrls(array $wordpress): array
    {
        $urls = Arr::wrap($wordpress['media_urls'] ?? []);

        if (is_string($wordpress['featured_media_url'] ?? null)) {
            $urls[] = $wordpress['featured_media_url'];
        }

        if (is_string($wordpress['thumbnail_media_url'] ?? null)) {
            $urls[] = $wordpress['thumbnail_media_url'];
        }

        return array_values(array_unique(array_filter(
            $urls,
            static fn (mixed $url): bool => is_string($url) && filter_var($url, FILTER_VALIDATE_URL) !== false,
        )));
    }

    private function downloadAndAttach(Page $page, string $mediaUrl, string $collection): ?MediaContract
    {
        $temporaryPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'capell-wp-media-' . Str::random(32);

        try {
            // The guarded client resolves the host once, refuses it unless every
            // address is public, pins the connection to the checked address,
            // refuses redirects and aborts the transfer past the byte limit.
            $response = OutboundHttp::guarded(new OutboundHttpPolicy(
                schemes: ['https', 'http'],
                ports: [$this->validatedPort($mediaUrl)],
                maxBytes: $this->maximumMediaBytes(),
                connectTimeoutSeconds: min(10, $this->timeoutSeconds()),
                timeoutSeconds: $this->timeoutSeconds(),
            ))
                ->withOptions(['sink' => $temporaryPath])
                ->get($mediaUrl);

            if (! $response->successful()) {
                Log::warning('WordPress media import download failed.', [
                    ...$this->failureContext($mediaUrl),
                    'status' => $response->status(),
                ]);

                return null;
            }

            $contentLength = $response->header('Content-Length');

            if (is_numeric($contentLength) && (int) $contentLength > $this->maximumMediaBytes()) {
                $this->logOversizedMedia($mediaUrl, (int) $contentLength);

                return null;
            }

            if (! File::exists($temporaryPath) || File::size($temporaryPath) === 0) {
                $body = $response->body();

                if ($body === '') {
                    Log::warning('WordPress media import download failed.', [
                        ...$this->failureContext($mediaUrl),
                        'status' => $response->status(),
                        'empty_body' => true,
                    ]);

                    return null;
                }

                if (strlen($body) > $this->maximumMediaBytes()) {
                    $this->logOversizedMedia($mediaUrl, strlen($body));

                    return null;
                }

                File::put($temporaryPath, $body);
            }

            if (File::size($temporaryPath) > $this->maximumMediaBytes()) {
                $this->logOversizedMedia($mediaUrl, File::size($temporaryPath));

                return null;
            }

            // capell-security-scan: local-stream reads the temporary file the download was written to
            $imageInfo = @getimagesize($temporaryPath);
            $detectedMime = is_array($imageInfo) ? $imageInfo['mime'] : null;
            if (! in_array($detectedMime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'], true)) {
                Log::warning('WordPress media import rejected non-image content.', [
                    ...$this->failureContext($mediaUrl),
                    'detected_mime' => $detectedMime ?? 'unknown',
                ]);

                return null;
            }

            // capell-security-scan: local-stream reads the temporary file the download was written to
            $stream = fopen($temporaryPath, 'rb');
            throw_unless(is_resource($stream), RuntimeException::class, 'Failed to read downloaded WordPress media.');

            try {
                $checksum = hash_file('sha256', $temporaryPath);
                throw_unless(is_string($checksum), RuntimeException::class, 'Failed to checksum downloaded WordPress media.');

                return (new StoreImportedMediaAction)->handle(
                    $stream,
                    $this->fileName($mediaUrl),
                    'sha256-' . $checksum,
                    File::size($temporaryPath),
                    $page,
                    $collection,
                );
            } finally {
                fclose($stream);
            }
        } catch (OutboundRequestRefusedException $exception) {
            if ($exception->reason === OutboundRequestRefusedException::REASON_SIZE) {
                $this->logOversizedMedia($mediaUrl, null);

                return null;
            }

            Log::warning('WordPress media import attachment failed.', [
                ...$this->failureContext($mediaUrl),
                'exception' => $exception::class,
                'reason' => $exception->reason,
            ]);

            return null;
        } catch (Throwable $throwable) {
            Log::warning('WordPress media import attachment failed.', [
                ...$this->failureContext($mediaUrl),
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);

            return null;
        } finally {
            File::delete($temporaryPath);
        }
    }

    private function recordMediaCheckpoint(
        ?ImportSession $session,
        Page $page,
        string $mediaUrl,
        bool $successful,
        bool $newlyImported,
    ): void {
        if (! $session instanceof ImportSession) {
            return;
        }

        $summary = is_array($session->result_summary) ? $session->result_summary : [];
        $checkpoint = is_array($summary['wordpress_media'] ?? null) ? $summary['wordpress_media'] : [];
        $failedUrls = is_array($checkpoint['failed_urls'] ?? null) ? $checkpoint['failed_urls'] : [];
        $pageKey = $page->getKey();
        $checkpointKey = (is_int($pageKey) || is_string($pageKey) ? (string) $pageKey : 'unknown') . ':' . hash('sha256', $mediaUrl);

        if ($successful) {
            unset($failedUrls[$checkpointKey]);
        } else {
            $failedUrls[$checkpointKey] = [
                'page_id' => $page->getKey(),
                'source_hash' => hash('sha256', $mediaUrl),
            ];
        }

        $checkpoint['failed_urls'] = $failedUrls;

        if ($newlyImported) {
            $importedCount = $checkpoint['imported_count'] ?? 0;
            $checkpoint['imported_count'] = (is_int($importedCount) ? $importedCount : 0) + 1;
        }

        $summary['wordpress_media'] = $checkpoint;
        $session->forceFill(['result_summary' => $summary])->save();
    }

    private function recordCompletedPageCheckpoint(?ImportSession $session, Page $page): void
    {
        if (! $session instanceof ImportSession) {
            return;
        }

        $summary = is_array($session->result_summary) ? $session->result_summary : [];
        $checkpoint = is_array($summary['wordpress_media'] ?? null) ? $summary['wordpress_media'] : [];
        $completedPageIds = is_array($checkpoint['completed_page_ids'] ?? null) ? $checkpoint['completed_page_ids'] : [];
        $completedPageIds[] = $page->getKey();
        $checkpoint['completed_page_ids'] = array_values(array_unique($completedPageIds, SORT_REGULAR));
        $summary['wordpress_media'] = $checkpoint;
        $session->forceFill(['result_summary' => $summary])->save();
    }

    /** @param int|null $bytes null when the transfer was stopped before its size was known */
    private function logOversizedMedia(string $mediaUrl, ?int $bytes): void
    {
        Log::warning('WordPress media import download exceeded the configured size limit.', [
            ...$this->failureContext($mediaUrl),
            'bytes' => $bytes,
            'max_bytes' => $this->maximumMediaBytes(),
        ]);
    }

    private function timeoutSeconds(): int
    {
        $configured = config('wordpress-importer.media.timeout_seconds', 20);

        return is_numeric($configured) ? max(1, (int) $configured) : 20;
    }

    private function maximumMediaBytes(): int
    {
        $configured = config('wordpress-importer.media.max_bytes', 50 * 1024 * 1024);

        return is_numeric($configured) ? max(1, (int) $configured) : 50 * 1024 * 1024;
    }

    /**
     * @return array{url_host: string|null, url_path: string|null}
     */
    private function failureContext(string $mediaUrl): array
    {
        $parts = parse_url($mediaUrl);

        return [
            'url_host' => is_array($parts) && is_string($parts['host'] ?? null) ? strtolower($parts['host']) : null,
            'url_path' => is_array($parts) && is_string($parts['path'] ?? null) ? $parts['path'] : null,
        ];
    }

    /**
     * Validates the media URL and returns its port. Host-label refusal happens
     * here because "localhost" may never be fetched, whatever a resolver answers.
     */
    private function validatedPort(string $url): int
    {
        $parts = parse_url($url);

        throw_if(! is_array($parts), InvalidArgumentException::class, 'WordPress media URL must be an absolute HTTP URL.');

        $scheme = is_string($parts['scheme'] ?? null) ? strtolower($parts['scheme']) : null;
        $host = is_string($parts['host'] ?? null) ? strtolower($parts['host']) : null;

        throw_if(! in_array($scheme, ['https', 'http'], true) || $host === null || $host === '', InvalidArgumentException::class, 'WordPress media URL must be an absolute HTTP URL.');
        throw_if($this->isPrivateHostLabel($host), InvalidArgumentException::class, 'WordPress media URL host is not allowed.');

        $port = $parts['port'] ?? null;

        if (is_int($port) && $port > 0 && $port <= 65535) {
            return $port;
        }

        return $scheme === 'https' ? 443 : 80;
    }

    private function isPrivateHostLabel(string $host): bool
    {
        return in_array($host, ['localhost', 'localhost.localdomain'], true) || str_ends_with($host, '.localhost');
    }

    private function fileName(string $mediaUrl): string
    {
        $path = parse_url($mediaUrl, PHP_URL_PATH);
        $basename = is_string($path) ? basename($path) : '';

        return $basename !== '' && str_contains($basename, '.')
            ? $basename
            : Str::uuid()->toString() . '.bin';
    }
}
