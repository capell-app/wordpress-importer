<?php

declare(strict_types=1);

use Capell\Core\Models\Page;
use Capell\WordPressImporter\Actions\ImportWordPressMediaForPagesAction;
use Capell\WordPressImporter\Contracts\WordPressMediaHostResolver;
use Capell\WordPressImporter\Tests\Fixtures\StaticWordPressMediaHostResolver;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('does not request imported WordPress media from private hosts', function (): void {
    Http::fake();

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
});

it('does not follow imported WordPress media redirects to unchecked targets', function (): void {
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
});
