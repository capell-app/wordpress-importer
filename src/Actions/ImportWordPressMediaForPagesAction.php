<?php

declare(strict_types=1);

namespace Capell\WordPressImporter\Actions;

use Capell\Core\Contracts\Media\MediaContract;
use Capell\Core\Models\Page;
use Capell\WordPressImporter\Contracts\WordPressMediaHostResolver;
use Capell\WordPressImporter\Data\ResolvedWordPressMediaEndpointData;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

/**
 * @method static int run(iterable $pages)
 */
final class ImportWordPressMediaForPagesAction
{
    use AsObject;

    private const string COLLECTION = 'wordpress-import';

    public function __construct(
        private readonly ?HttpFactory $http = null,
        private readonly ?WordPressMediaHostResolver $hostResolver = null,
    ) {}

    public function handle(iterable $pages): int
    {
        $imported = 0;

        foreach ($pages as $page) {
            if (! $page instanceof Page) {
                continue;
            }

            $imported += $this->importForPage($page);
        }

        return $imported;
    }

    private function importForPage(Page $page): int
    {
        $meta = is_array($page->getAttribute('meta')) ? $page->getAttribute('meta') : [];
        $wordpress = is_array($meta['wordpress'] ?? null) ? $meta['wordpress'] : [];
        $mediaUrls = $this->mediaUrls($wordpress);

        if ($mediaUrls === []) {
            return 0;
        }

        $content = is_string($meta['content'] ?? null) ? $meta['content'] : null;
        $importedMedia = [];

        foreach ($mediaUrls as $mediaUrl) {
            $media = $this->downloadAndAttach($page, $mediaUrl);

            if (! $media instanceof MediaContract) {
                continue;
            }

            $localUrl = $media->getFullUrl();
            $importedMedia[] = [
                'source_url' => $mediaUrl,
                'media_url' => $localUrl,
            ];

            if ($content !== null && $localUrl !== '') {
                $content = str_replace($mediaUrl, $localUrl, $content);
            }
        }

        if ($importedMedia === []) {
            return 0;
        }

        if ($content !== null) {
            $meta['content'] = $content;
        }

        $meta['wordpress'] = array_replace($wordpress, [
            'imported_media' => $importedMedia,
        ]);

        $page->forceFill(['meta' => $meta])->save();

        return count($importedMedia);
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
        $temporaryPath = tempnam(sys_get_temp_dir(), 'capell-wp-media-');

        if ($temporaryPath === false) {
            return null;
        }

        try {
            $endpoint = $this->endpoint($mediaUrl);
            $response = ($this->http ?? resolve(HttpFactory::class))
                ->timeout(20)
                ->withoutRedirecting()
                ->withHeaders(['Host' => $endpoint->hostHeader()])
                ->withOptions($this->requestOptions($endpoint))
                ->get($endpoint->url);

            if (! $response->successful() || $response->body() === '') {
                return null;
            }

            File::put($temporaryPath, $response->body());

            $uploadedFile = new UploadedFile(
                path: $temporaryPath,
                originalName: $this->fileName($mediaUrl),
                mimeType: $response->header('Content-Type') ?: null,
                error: null,
                test: true,
            );

            return $page->addMediaFromUploadedFile($uploadedFile, self::COLLECTION);
        } catch (Throwable) {
            return null;
        } finally {
            File::delete($temporaryPath);
        }
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
