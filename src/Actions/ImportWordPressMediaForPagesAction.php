<?php

declare(strict_types=1);

namespace Capell\WordPressImporter\Actions;

use Capell\Core\Contracts\Media\MediaContract;
use Capell\Core\Models\Page;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

/**
 * @method static int run(iterable $pages)
 */
final class ImportWordPressMediaForPagesAction
{
    use AsObject;

    private const string COLLECTION = 'wordpress-import';

    public function __construct(private readonly ?HttpFactory $http = null) {}

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
            $response = ($this->http ?? app(HttpFactory::class))
                ->timeout(20)
                ->get($mediaUrl);

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

    private function fileName(string $mediaUrl): string
    {
        $path = parse_url($mediaUrl, PHP_URL_PATH);
        $basename = is_string($path) ? basename($path) : '';

        return $basename !== '' && str_contains($basename, '.')
            ? $basename
            : Str::uuid()->toString() . '.bin';
    }
}
