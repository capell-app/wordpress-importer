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

/**
 * @param  array<array-key, mixed>  $manifest
 * @return array<array-key, mixed>
 */
function wordpressImporterManifestArray(array $manifest, string $key): array
{
    $value = data_get($manifest, $key);

    throw_unless(is_array($value), RuntimeException::class, sprintf('Expected WordPress Importer manifest [%s] to be an array.', $key));

    return $value;
}

/**
 * @param  array<array-key, mixed>  $manifest
 */
function wordpressImporterManifestString(array $manifest, string $key): string
{
    $value = data_get($manifest, $key);

    throw_unless(is_string($value), RuntimeException::class, sprintf('Expected WordPress Importer manifest [%s] to be a string.', $key));

    return $value;
}

it('declares the shipped wordpress importer manifest surfaces', function (): void {
    $packagePath = dirname(__DIR__, 2);
    $manifest = capell_json_file_array($packagePath . '/capell.json');
    $composer = capell_json_file_array($packagePath . '/composer.json');

    (new ManifestValidator)->validate($manifest, $composer, 'capell-app/wordpress-importer', $packagePath . '/capell.json');

    expect($manifest)
        ->toHaveKey('manifest-version', 3)
        ->toHaveKey('name', 'capell-app/wordpress-importer')
        ->toHaveKey('namespace', 'Capell\\WordPressImporter')
        ->and(wordpressImporterManifestArray($manifest, 'surfaces'))->toContain('admin', 'console')
        ->and(wordpressImporterManifestArray($manifest, 'dependencies.requires'))->toContain(
            'capell-app/admin',
            'capell-app/core',
            'capell-app/migration-assistant',
        )
        ->and(wordpressImporterManifestArray($manifest, 'providers.runtime'))->toContain(WordPressImporterServiceProvider::class)
        ->and(wordpressImporterManifestString($manifest, 'commands.import'))->toBe('wordpress-importer:import')
        ->and(class_exists(ImportWordPressWxrCommand::class))->toBeTrue()
        ->and(wordpressImporterManifestArray($manifest, 'contributes'))->toContain([
            'type' => 'health-check',
            'class' => WordpressImporterHealthCheck::class,
        ])
        ->and(wordpressImporterManifestString($manifest, 'healthChecks.0.class'))->toBe(WordpressImporterHealthCheck::class)
        ->and(class_implements(WordpressImporterHealthCheck::class))->toContain(ChecksExtensionHealth::class)
        ->and(class_implements(WxrReader::class))->toContain(ImportSourceReader::class)
        ->and(wordpressImporterManifestArray($manifest, 'capabilities'))->toContain(
            'wordpress-wxr-reader',
            'wordpress-wxr-preview',
            'wordpress-permalink-redirects',
            'wordpress-headless-preview',
        )
        ->and(wordpressImporterManifestArray($manifest, 'contributionTraceability.deferredContributions'))->toBe([]);
});

it('declares committed marketplace assets for required wordpress importer screenshot targets', function (): void {
    $packagePath = dirname(__DIR__, 2);
    $manifest = capell_json_file_array($packagePath . '/capell.json');
    $screenshotContract = capell_json_file_array($packagePath . '/docs/screenshots.json');

    $marketplaceScreenshots = wordpressImporterManifestArray($manifest, 'marketplace.screenshots');
    $contractEntries = wordpressImporterManifestArray($screenshotContract, 'entries');

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
