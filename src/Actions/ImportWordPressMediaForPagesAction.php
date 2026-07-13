<?php

declare(strict_types=1);

namespace Capell\WordPressImporter\Actions;

use Capell\Core\Contracts\Media\MediaContract;
use Capell\Core\Models\Page;
use Capell\MigrationAssistant\Models\ImportSession;
use Capell\WordPressImporter\Contracts\WordPressMediaHostResolver;
use Capell\WordPressImporter\Data\ResolvedWordPressMediaEndpointData;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;
use Throwable;

/**
 * @method static int run(iterable<array-key, mixed> $pages, ?ImportSession $session = null)
 */
final class ImportWordPressMediaForPagesAction
{
    use AsObject;

    private const string COLLECTION = 'wordpress-import';

    public function __construct(
        private readonly ?HttpFactory $http = null,
        private readonly ?WordPressMediaHostResolver $hostResolver = null,
    ) {}

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

            $media = $this->downloadAndAttach($page, $mediaUrl);

            if (! $media instanceof MediaContract) {
                $failedUrls[] = $mediaUrl;
                $this->recordMediaCheckpoint($session, $page, $mediaUrl, successful: false, newlyImported: false);

                continue;
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
     * @param  array<string, mixed>  $wordpress
     * @return list<string>
     */
    private function mediaUrls(array $wordpress): array
    {
        $urls = Arr::wrap($wordpress['media_urls'] ?? []);

        if (is_string($wordpress['featured_media_url'] ?? null)) {
            $urls[] = $wordpress['featured_media_url'];
        }

        return array_values(array_unique(array_filter(
            $urls,
            static fn (mixed $url): bool => is_string($url) && filter_var($url, FILTER_VALIDATE_URL) !== false,
        )));
    }

    private function downloadAndAttach(Page $page, string $mediaUrl): ?MediaContract
    {
        $temporaryPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'capell-wp-media-' . Str::random(32);

        try {
            $endpoint = $this->endpoint($mediaUrl);
            $response = ($this->http ?? resolve(HttpFactory::class))
                ->timeout($this->timeoutSeconds())
                ->withoutRedirecting()
                ->withHeaders(['Host' => $endpoint->hostHeader()])
                ->withOptions([
                    ...$this->requestOptions($endpoint),
                    'sink' => $temporaryPath,
                ])
                ->get($endpoint->url);

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

            $imageInfo = @getimagesize($temporaryPath);
            $detectedMime = is_array($imageInfo) ? $imageInfo['mime'] : null;
            if (! in_array($detectedMime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'], true)) {
                Log::warning('WordPress media import rejected non-image content.', [
                    ...$this->failureContext($mediaUrl),
                    'detected_mime' => $detectedMime ?? 'unknown',
                ]);

                return null;
            }

            $uploadedFile = new UploadedFile(
                path: $temporaryPath,
                originalName: $this->fileName($mediaUrl),
                mimeType: $detectedMime,
                error: null,
                test: true,
            );

            return $page->addMediaFromUploadedFile($uploadedFile, self::COLLECTION);
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

    private function logOversizedMedia(string $mediaUrl, int $bytes): void
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

    private function endpoint(string $url): ResolvedWordPressMediaEndpointData
    {
        $parts = parse_url($url);

        throw_if(! is_array($parts), InvalidArgumentException::class, 'WordPress media URL must be an absolute HTTP URL.');

        $scheme = is_string($parts['scheme'] ?? null) ? strtolower($parts['scheme']) : null;
        $host = is_string($parts['host'] ?? null) ? strtolower($parts['host']) : null;

        throw_if(! in_array($scheme, ['https', 'http'], true) || $host === null || $host === '', InvalidArgumentException::class, 'WordPress media URL must be an absolute HTTP URL.');

        $addresses = $this->resolvedHostAddresses($host);

        throw_if($addresses === [], InvalidArgumentException::class, 'WordPress media URL host could not be resolved.');
        throw_if($this->hasPrivateAddress($addresses), InvalidArgumentException::class, 'WordPress media URL host is not allowed.');

        return new ResolvedWordPressMediaEndpointData(
            url: $url,
            scheme: $scheme,
            host: $host,
            port: $this->port($parts, $scheme),
            address: $addresses[0],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function requestOptions(ResolvedWordPressMediaEndpointData $endpoint): array
    {
        throw_unless(defined('CURLOPT_RESOLVE'), InvalidArgumentException::class, 'WordPress media imports require cURL host pinning support.');

        return [
            'curl' => [
                CURLOPT_RESOLVE => [$endpoint->curlResolveEntry()],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $parts
     */
    private function port(array $parts, string $scheme): int
    {
        $port = $parts['port'] ?? null;

        if (is_int($port) && $port > 0 && $port <= 65535) {
            return $port;
        }

        return $scheme === 'https' ? 443 : 80;
    }

    /**
     * @param  list<string>  $addresses
     */
    private function hasPrivateAddress(array $addresses): bool
    {
        foreach ($addresses as $address) {
            if ($this->isPrivateAddress($address)) {
                return true;
            }
        }

        return false;
    }

    private function isPrivateHostLabel(string $host): bool
    {
        return in_array($host, ['localhost', 'localhost.localdomain'], true) || str_ends_with($host, '.localhost');
    }

    private function isPrivateAddress(string $address): bool
    {
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false;
    }

    /**
     * @return list<string>
     */
    private function resolvedHostAddresses(string $host): array
    {
        if ($this->isPrivateHostLabel($host)) {
            return ['127.0.0.1'];
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $addresses = ($this->hostResolver ?? resolve(WordPressMediaHostResolver::class))->resolve($host);

        return array_values(collect($addresses)
            ->filter(static fn (string $address): bool => filter_var($address, FILTER_VALIDATE_IP) !== false)
            ->unique()
            ->values()
            ->all());
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
