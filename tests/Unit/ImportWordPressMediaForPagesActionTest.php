<?php

declare(strict_types=1);

use Capell\Core\Models\Page;
use Capell\WordPressImporter\Actions\ImportWordPressMediaForPagesAction;
use Capell\WordPressImporter\Contracts\WordPressMediaHostResolver;
use Capell\WordPressImporter\Tests\Fixtures\StaticWordPressMediaHostResolver;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;

it('does not request imported WordPress media from private hosts', function (): void {
    Http::fake();
    Log::spy();

    app()->instance(WordPressMediaHostResolver::class, new StaticWordPressMediaHostResolver([]));

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
                && ($context['exception'] ?? null) === InvalidArgumentException::class,
        ));
});

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

    app()->instance(WordPressMediaHostResolver::class, new StaticWordPressMediaHostResolver([
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
    app()->instance(WordPressMediaHostResolver::class, new StaticWordPressMediaHostResolver([
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
            fn (array $context): bool => ($context['bytes'] ?? null) === 5
                && ($context['max_bytes'] ?? null) === 4,
        ));
});

it('aborts chunked media during transfer when content length is unavailable', function (): void {
    config()->set('wordpress-importer.media.max_bytes', 4);
    Log::spy();

    Http::fake(function (Request $request, array $options): PromiseInterface {
        expect($request->url())->toBe('https://media.example.test/chunked.jpg')
            ->and($options)->toHaveKey('sink')
            ->toHaveKey('progress');

        $progress = $options['progress'];
        throw_unless(is_callable($progress), RuntimeException::class, 'Expected a transfer progress callback.');
        $progress(0, 3, 0, 0);
        $progress(0, 5, 0, 0);

        return Http::response('unreachable', 200, ['Transfer-Encoding' => 'chunked']);
    });

    app()->instance(WordPressMediaHostResolver::class, new StaticWordPressMediaHostResolver([
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
            fn (array $context): bool => ($context['bytes'] ?? null) === 5
                && ($context['max_bytes'] ?? null) === 4,
        ));
});

it('rejects non-image bytes even when the response claims an image MIME type', function (): void {
    Log::spy();
    Http::fake([
        'https://media.example.test/fake.jpg' => Http::response('<html>not an image</html>', 200, ['Content-Type' => 'image/jpeg']),
    ]);
    app()->instance(WordPressMediaHostResolver::class, new StaticWordPressMediaHostResolver([
        'media.example.test' => ['93.184.216.34'],
    ]));
    $page = Page::factory()->create(['meta' => ['wordpress' => ['media_urls' => ['https://media.example.test/fake.jpg']]]]);

    expect(ImportWordPressMediaForPagesAction::run([$page]))->toBe(0);

    wordpressImporterLogger()->shouldHaveReceived('warning')->once()->with(
        'WordPress media import rejected non-image content.',
        Mockery::on(fn (array $context): bool => ($context['detected_mime'] ?? null) === 'unknown'),
    );
});
