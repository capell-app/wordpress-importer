<?php

declare(strict_types=1);

use Capell\Core\Contracts\Security\ResolvesHostAddresses;
use Capell\Core\Exceptions\OutboundRequestRefusedException;
use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Capell\WordPressImporter\Actions\ImportWordPressMediaForPagesAction;
use Capell\WordPressImporter\Tests\Fixtures\StaticWordPressMediaHostResolver;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;

it('does not request imported WordPress media from private hosts', function (): void {
    Http::fake();
    Log::spy();

    app()->instance(ResolvesHostAddresses::class, new StaticWordPressMediaHostResolver([]));

    $page = Page::factory()->create([
        'meta' => [
            'content' => '<p><img src="http://127.0.0.1/private.jpg"></p>',
            'wordpress' => [
                'media_urls' => ['http://127.0.0.1/private.jpg'],
            ],
        ],
    ]);

    $imported = ImportWordPressMediaForPagesAction::run([$page]);

    expect($imported)->toBe(0);

    Http::assertNothingSent();
    wordpressImporterLogger()->shouldHaveReceived('warning')
        ->once()
        ->with('WordPress media import attachment failed.', Mockery::on(
            fn (array $context): bool => ($context['url_host'] ?? null) === '127.0.0.1'
                && ($context['url_path'] ?? null) === '/private.jpg'
                && ($context['exception'] ?? null) === OutboundRequestRefusedException::class
                && ($context['reason'] ?? null) === OutboundRequestRefusedException::REASON_ADDRESS,
        ));
});

it('refuses WordPress media whose host resolves to a loopback or metadata address before sending anything', function (string $address): void {
    Http::fake();
    Log::spy();
    app()->instance(ResolvesHostAddresses::class, new StaticWordPressMediaHostResolver(['media.example.test' => [$address]]));
    $page = Page::factory()->create(['meta' => ['wordpress' => ['media_urls' => ['https://media.example.test/image.jpg']]]]);

    expect(ImportWordPressMediaForPagesAction::run([$page]))->toBe(0);

    Http::assertNothingSent();
    wordpressImporterLogger()->shouldHaveReceived('warning')
        ->once()
        ->with('WordPress media import attachment failed.', Mockery::on(
            fn (array $context): bool => ($context['reason'] ?? null) === OutboundRequestRefusedException::REASON_ADDRESS,
        ));
})->with(['loopback' => '127.0.0.1', 'cloud metadata' => '169.254.169.254']);

it('downloads WordPress media from a host that resolves to a public address', function (): void {
    Storage::fake('public');
    $url = 'https://media.example.test/image.png';
    Http::fake([$url => Http::response(wordPressImportedPngBytes(), 200, ['Content-Type' => 'image/png'])]);
    app()->instance(ResolvesHostAddresses::class, new StaticWordPressMediaHostResolver(['media.example.test' => ['93.184.216.34']]));
    $page = Page::factory()->create(['meta' => ['wordpress' => ['media_urls' => [$url]]]]);

    expect(ImportWordPressMediaForPagesAction::run([$page]))->toBe(1);

    Http::assertSent(fn (Request $request): bool => $request->url() === $url);
});

it('refuses localhost media URLs by name before resolving or sending', function (): void {
    Http::fake();
    Log::spy();
    app()->instance(ResolvesHostAddresses::class, new StaticWordPressMediaHostResolver(['localhost' => ['93.184.216.34']]));
    $page = Page::factory()->create(['meta' => ['wordpress' => ['media_urls' => ['http://localhost/private.jpg']]]]);

    expect(ImportWordPressMediaForPagesAction::run([$page]))->toBe(0);

    Http::assertNothingSent();
    wordpressImporterLogger()->shouldHaveReceived('warning')
        ->once()
        ->with('WordPress media import attachment failed.', Mockery::on(
            fn (array $context): bool => ($context['exception'] ?? null) === InvalidArgumentException::class,
        ));
});

it('does not request imported WordPress media from internal addresses that PHP treats as public', function (string $address): void {
    Http::fake();

    app()->instance(ResolvesHostAddresses::class, new StaticWordPressMediaHostResolver(['media.example.test' => [$address]]));

    $page = Page::factory()->create([
        'meta' => [
            'content' => '<p><img src="https://media.example.test/internal.jpg"></p>',
            'wordpress' => [
                'media_urls' => ['https://media.example.test/internal.jpg'],
            ],
        ],
    ]);

    expect(ImportWordPressMediaForPagesAction::run([$page]))->toBe(0);

    Http::assertNothingSent();
})->with([
    'carrier-grade NAT' => '100.64.0.10',
    'benchmarking range' => '198.18.0.10',
]);

function wordpressImporterLogger(): MockInterface
{
    $logger = Log::getFacadeRoot();

    if (! $logger instanceof MockInterface) {
        throw new RuntimeException('Expected a spied logger.');
    }

    return $logger;
}

it('does not follow imported WordPress media redirects to unchecked targets', function (): void {
    Log::spy();

    /** @var array<string, mixed>|null $requestOptions */
    $requestOptions = null;

    Http::fake(function (Request $request, array $options) use (&$requestOptions): PromiseInterface {
        $requestOptions = $options;

        return Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data']);
    });

    app()->instance(ResolvesHostAddresses::class, new StaticWordPressMediaHostResolver([
        'media.example.test' => ['93.184.216.34'],
    ]));

    $page = Page::factory()->create([
        'meta' => [
            'content' => '<p><img src="https://media.example.test/image.jpg"></p>',
            'wordpress' => [
                'media_urls' => ['https://media.example.test/image.jpg'],
            ],
        ],
    ]);

    $imported = ImportWordPressMediaForPagesAction::run([$page]);

    expect($imported)->toBe(0)
        ->and(data_get($requestOptions, 'allow_redirects'))->toBeFalse()
        ->and(data_get($requestOptions, 'curl.' . CURLOPT_RESOLVE))->toBe(['media.example.test:443:93.184.216.34']);

    Http::assertSentCount(1);
    wordpressImporterLogger()->shouldHaveReceived('warning')
        ->once()
        ->with('WordPress media import download failed.', Mockery::on(
            fn (array $context): bool => ($context['url_host'] ?? null) === 'media.example.test'
                && ($context['url_path'] ?? null) === '/image.jpg'
                && ($context['status'] ?? null) === 302,
        ));
});

it('rejects remote media larger than the configured byte cap', function (): void {
    config()->set('wordpress-importer.media.max_bytes', 4);
    Log::spy();
    Http::fake([
        'https://media.example.test/large.jpg' => Http::response('12345', 200, [
            'Content-Type' => 'image/jpeg',
            'Content-Length' => '5',
        ]),
    ]);
    app()->instance(ResolvesHostAddresses::class, new StaticWordPressMediaHostResolver([
        'media.example.test' => ['93.184.216.34'],
    ]));

    $page = Page::factory()->create([
        'meta' => [
            'content' => '<p><img src="https://media.example.test/large.jpg"></p>',
            'wordpress' => [
                'media_urls' => ['https://media.example.test/large.jpg'],
            ],
        ],
    ]);

    expect(ImportWordPressMediaForPagesAction::run([$page]))->toBe(0);

    wordpressImporterLogger()->shouldHaveReceived('warning')
        ->once()
        ->with('WordPress media import download exceeded the configured size limit.', Mockery::on(
            fn (array $context): bool => array_key_exists('bytes', $context)
                && $context['bytes'] === null
                && ($context['max_bytes'] ?? null) === 4,
        ));
});

it('aborts chunked media during transfer when content length is unavailable', function (): void {
    config()->set('wordpress-importer.media.max_bytes', 4);
    Log::spy();

    Http::fake([
        'https://media.example.test/chunked.jpg' => Http::response('12345', 200, ['Transfer-Encoding' => 'chunked']),
    ]);

    app()->instance(ResolvesHostAddresses::class, new StaticWordPressMediaHostResolver([
        'media.example.test' => ['93.184.216.34'],
    ]));

    $page = Page::factory()->create([
        'meta' => [
            'wordpress' => [
                'media_urls' => ['https://media.example.test/chunked.jpg'],
            ],
        ],
    ]);

    expect(ImportWordPressMediaForPagesAction::run([$page]))->toBe(0);

    wordpressImporterLogger()->shouldHaveReceived('warning')
        ->once()
        ->with('WordPress media import download exceeded the configured size limit.', Mockery::on(
            fn (array $context): bool => array_key_exists('bytes', $context)
                && $context['bytes'] === null
                && ($context['max_bytes'] ?? null) === 4,
        ));
});

it('rejects non-image bytes even when the response claims an image MIME type', function (): void {
    Log::spy();
    Http::fake([
        'https://media.example.test/fake.jpg' => Http::response('<html>not an image</html>', 200, ['Content-Type' => 'image/jpeg']),
    ]);
    app()->instance(ResolvesHostAddresses::class, new StaticWordPressMediaHostResolver([
        'media.example.test' => ['93.184.216.34'],
    ]));
    $page = Page::factory()->create(['meta' => ['wordpress' => ['media_urls' => ['https://media.example.test/fake.jpg']]]]);

    expect(ImportWordPressMediaForPagesAction::run([$page]))->toBe(0);

    wordpressImporterLogger()->shouldHaveReceived('warning')->once()->with(
        'WordPress media import rejected non-image content.',
        Mockery::on(fn (array $context): bool => ($context['detected_mime'] ?? null) === 'unknown'),
    );
});

function wordPressImportedPngBytes(): string
{
    $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAADElEQVQImWNgYGAAAAAEAAGjChXjAAAAAElFTkSuQmCC', true);
    throw_unless(is_string($bytes), RuntimeException::class);

    return $bytes;
}

it('stores imported WordPress image bytes with a canonical extension', function (string $suffix): void {
    Queue::fake();
    $disk = Storage::fake('public');
    $url = 'https://media.example.test/image.' . $suffix;
    $bytes = wordPressImportedPngBytes();
    Http::fake([$url => Http::response($bytes, 200, ['Content-Type' => 'text/html'])]);
    app()->instance(ResolvesHostAddresses::class, new StaticWordPressMediaHostResolver([
        'media.example.test' => ['93.184.216.34'],
    ]));
    $page = Page::factory()->create(['meta' => ['wordpress' => ['media_urls' => [$url]]]]);

    expect(ImportWordPressMediaForPagesAction::run([$page]))->toBe(1);
    $media = Media::query()->sole();
    expect($media->file_name)->toBe('image.png')
        ->and($media->mime_type)->toBe('image/png')
        ->and($disk->get($media->getPathRelativeToRoot()))->toBe($bytes)
        ->and($page->fresh()?->getAttribute('meta')['wordpress']['imported_media'][0]['media_url'])
        ->toEndWith('/image.png');
})->with(['html', 'php']);

it('refuses active WordPress media payloads before storing or attaching them', function (string $kind): void {
    Queue::fake();
    $disk = Storage::fake('public');
    $bytes = match ($kind) {
        'SVG' => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
        'HTML' => '<html><script>alert(1)</script></html>',
        'PNG with appended HTML' => wordPressImportedPngBytes() . '<html><script>alert(1)</script></html>',
        default => throw new InvalidArgumentException('Unknown payload.'),
    };
    $url = 'https://media.example.test/image.png';
    Http::fake([$url => Http::response($bytes, 200, ['Content-Type' => 'image/png'])]);
    app()->instance(ResolvesHostAddresses::class, new StaticWordPressMediaHostResolver([
        'media.example.test' => ['93.184.216.34'],
    ]));
    $page = Page::factory()->create(['meta' => ['wordpress' => ['media_urls' => [$url]]]]);

    expect(ImportWordPressMediaForPagesAction::run([$page]))->toBe(0)
        ->and(Media::query()->count())->toBe(0)
        ->and($disk->allFiles())->toBe([])
        ->and($page->fresh()?->getAttribute('meta')['wordpress'])->not->toHaveKey('imported_media');
})->with(['SVG', 'HTML', 'PNG with appended HTML']);

it('refuses a WordPress media destination in executable application paths', function (): void {
    config()->set('media-library.disk_name', 'unsafe-wordpress');
    config()->set('filesystems.disks.unsafe-wordpress', ['driver' => 'local', 'root' => public_path('wordpress-media')]);
    $url = 'https://media.example.test/image.png';
    Http::fake([$url => Http::response(wordPressImportedPngBytes(), 200)]);
    app()->instance(ResolvesHostAddresses::class, new StaticWordPressMediaHostResolver([
        'media.example.test' => ['93.184.216.34'],
    ]));
    $page = Page::factory()->create(['meta' => ['wordpress' => ['media_urls' => [$url]]]]);

    expect(ImportWordPressMediaForPagesAction::run([$page]))->toBe(0)
        ->and(Media::query()->count())->toBe(0);
});

it('makes the featured image the page image even when the body uses the same file, and never replaces an image the page already has', function (): void {
    Storage::fake('public');
    $featuredUrl = 'https://media.example.test/featured.png';
    $bodyUrl = 'https://media.example.test/body.png';
    Http::fake(['media.example.test/*' => Http::response(wordPressImportedPngBytes(), 200, ['Content-Type' => 'image/png'])]);
    app()->instance(ResolvesHostAddresses::class, new StaticWordPressMediaHostResolver(['media.example.test' => ['93.184.216.34']]));
    $wordpress = ['media_urls' => [$bodyUrl], 'featured_media_url' => $featuredUrl, 'thumbnail_media_url' => $featuredUrl];
    $imported = Page::factory()->create(['meta' => ['wordpress' => $wordpress]]);
    $edited = Page::factory()->create(['meta' => ['wordpress' => $wordpress]]);
    $editorImage = $edited->addMediaFromString(wordPressImportedPngBytes())->usingFileName('editor.png')->toMediaCollection('image');
    $bodyOnly = Page::factory()->create(['meta' => ['wordpress' => ['media_urls' => [$bodyUrl], 'featured_media_url' => $bodyUrl]]]);

    expect(ImportWordPressMediaForPagesAction::run([$imported, $edited, $bodyOnly]))->toBe(5)
        ->and($imported->image()->sole()->getCustomProperty('checksum'))->toBe('sha256-' . hash('sha256', wordPressImportedPngBytes()))
        ->and($imported->media()->where('collection_name', 'wordpress-import')->exists())->toBeTrue()
        ->and($edited->image()->sole()->is($editorImage))->toBeTrue()
        ->and($bodyOnly->image()->exists())->toBeFalse();
});
