<?php

declare(strict_types=1);

use Capell\Core\Contracts\Extensions\ChecksExtensionHealth;
use Capell\Core\Support\Manifest\ManifestValidator;
use Capell\MigrationAssistant\Contracts\ImportSourceReader;
use Capell\WordPressImporter\Console\Commands\ImportWordPressWxrCommand;
use Capell\WordPressImporter\Health\WordpressImporterHealthCheck;
use Capell\WordPressImporter\Providers\WordPressImporterServiceProvider;
use Capell\WordPressImporter\Services\WxrReader;
use Illuminate\Support\Facades\File;

it('declares the shipped wordpress importer manifest surfaces', function (): void {
    $packagePath = dirname(__DIR__, 2);
    $manifest = json_decode(File::get($packagePath . '/capell.json'), true, flags: JSON_THROW_ON_ERROR);
    $composer = json_decode(File::get($packagePath . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);

    (new ManifestValidator)->validate($manifest, $composer, 'capell-app/wordpress-importer', $packagePath . '/capell.json');

    expect($manifest)
        ->toHaveKey('manifest-version', 3)
        ->toHaveKey('name', 'capell-app/wordpress-importer')
        ->toHaveKey('namespace', 'Capell\\WordPressImporter')
        ->and($manifest['surfaces'])->toContain('admin', 'console')
        ->and($manifest['dependencies']['requires'])->toContain(
            'capell-app/admin',
            'capell-app/core',
            'capell-app/migration-assistant',
        )
        ->and($manifest['providers']['runtime'])->toContain(WordPressImporterServiceProvider::class)
        ->and($manifest['commands']['import'])->toBe('wordpress-importer:import')
        ->and(class_exists(ImportWordPressWxrCommand::class))->toBeTrue()
        ->and($manifest['contributes'])->toContain([
            'type' => 'health-check',
            'class' => WordpressImporterHealthCheck::class,
        ])
        ->and($manifest['healthChecks'][0]['class'])->toBe(WordpressImporterHealthCheck::class)
        ->and(class_implements(WordpressImporterHealthCheck::class))->toContain(ChecksExtensionHealth::class)
        ->and(class_implements(WxrReader::class))->toContain(ImportSourceReader::class)
        ->and($manifest['capabilities'])->toContain(
            'wordpress-wxr-reader',
            'wordpress-wxr-preview',
            'wordpress-permalink-redirects',
            'wordpress-headless-preview',
        )
        ->and($manifest['contributionTraceability']['deferredContributions'])->toBe([]);
});

it('declares committed marketplace assets for required wordpress importer screenshot targets', function (): void {
    $packagePath = dirname(__DIR__, 2);
    $manifest = json_decode(File::get($packagePath . '/capell.json'), true, flags: JSON_THROW_ON_ERROR);
    $screenshotContract = json_decode(File::get($packagePath . '/docs/screenshots.json'), true, flags: JSON_THROW_ON_ERROR);

    $marketplaceScreenshots = $manifest['marketplace']['screenshots'] ?? [];
    $contractEntries = $screenshotContract['entries'] ?? [];

    throw_unless(is_array($marketplaceScreenshots), RuntimeException::class, 'WordPress Importer marketplace screenshots must be an array.');
    throw_unless(is_array($contractEntries), RuntimeException::class, 'WordPress Importer screenshot contract entries must be an array.');

    $marketplaceScreenshotPaths = [];

    foreach ($marketplaceScreenshots as $marketplaceScreenshot) {
        throw_unless(is_array($marketplaceScreenshot), RuntimeException::class, 'WordPress Importer marketplace screenshot entries must be arrays.');

        $path = $marketplaceScreenshot['path'] ?? null;
        $alt = $marketplaceScreenshot['alt'] ?? null;
        $caption = $marketplaceScreenshot['caption'] ?? null;

        throw_unless(is_string($path), RuntimeException::class, 'WordPress Importer marketplace screenshot paths must be strings.');
        throw_unless(is_string($alt), RuntimeException::class, 'WordPress Importer marketplace screenshot alt text must be strings.');
        throw_unless(is_string($caption), RuntimeException::class, 'WordPress Importer marketplace screenshot captions must be strings.');

        $marketplaceScreenshotPaths[] = $path;

        expect(str_starts_with($path, 'docs/assets/marketplace/') || str_starts_with($path, 'docs/screenshots/'))->toBeTrue()
            ->and(File::exists($packagePath . '/' . $path))->toBeTrue()
            ->and(strlen(trim($alt)))->toBeGreaterThanOrEqual(12)
            ->and(strlen(trim($caption)))->toBeGreaterThanOrEqual(12);
    }

    $requiredScreenshotPaths = [];

    foreach ($contractEntries as $contractEntry) {
        if (! is_array($contractEntry) || ($contractEntry['required'] ?? false) !== true) {
            continue;
        }

        $screenshotPath = $contractEntry['screenshotPath'] ?? null;

        throw_unless(is_string($screenshotPath), RuntimeException::class, 'Required WordPress Importer screenshot entries must have screenshot paths.');

        $requiredScreenshotPaths[] = str_replace('packages/wordpress-importer/', '', $screenshotPath);
    }

    expect($marketplaceScreenshotPaths)
        ->toContain('docs/assets/marketplace/extension-card.jpg')
        ->toContain(...$requiredScreenshotPaths);
});
