<?php

declare(strict_types=1);

use Capell\Core\Contracts\Extensions\ChecksExtensionHealth;
use Capell\Core\Contracts\Extensions\ExtensionContribution;
use Capell\Core\Support\Manifest\ManifestValidator;
use Capell\MigrationAssistant\Contracts\ImportSourceReader;
use Capell\WordPressImporter\Console\Commands\ImportWordPressWxrCommand;
use Capell\WordPressImporter\Health\WordpressImporterHealthCheck;
use Capell\WordPressImporter\Manifest\WordPressImporterConsoleCommandContribution;
use Capell\WordPressImporter\Providers\WordPressImporterServiceProvider;
use Capell\WordPressImporter\Services\WxrReader;
use Illuminate\Support\Facades\File;

it('declares the shipped wordpress importer manifest surfaces', function (): void {
    $packagePath = dirname(__DIR__, 2);
    $manifest = capell_json_file_array($packagePath . '/capell.json');
    $composer = capell_json_file_array($packagePath . '/composer.json');
    $contributions = data_get($manifest, 'contributes');

    throw_unless(is_array($contributions), RuntimeException::class, 'Expected WordPress Importer manifest contributions.');

    (new ManifestValidator)->validate($manifest, $composer, 'capell-app/wordpress-importer', $packagePath . '/capell.json');

    expect($manifest)
        ->toHaveKey('manifest-version', 3)
        ->toHaveKey('name', 'capell-app/wordpress-importer')
        ->toHaveKey('namespace', 'Capell\\WordPressImporter')
        ->and(data_get($manifest, 'surfaces', []))->toContain('admin', 'console')
        ->and(data_get($manifest, 'dependencies.requires', []))->toContain(
            'capell-app/admin',
            'capell-app/core',
            'capell-app/migration-assistant',
        )
        ->and(data_get($manifest, 'providers.runtime', []))->toContain(WordPressImporterServiceProvider::class)
        ->and(data_get($manifest, 'commands.import'))->toBe('wordpress-importer:import')
        ->and(data_get($manifest, 'product.tier'))->toBe('premium')
        ->and(data_get($manifest, 'commercial.proposedLicense'))->toBe('paid')
        ->and(data_get($manifest, 'commercial.requestedCertification'))->toBe('first-party')
        ->and(data_get($manifest, 'commercial.supportPolicy'))->toBe('priority')
        ->and(data_get($manifest, 'commercial.privateDocsRequested'))->toBeTrue()
        ->and($composer['license'] ?? null)->toBe('proprietary')
        ->and(class_exists(ImportWordPressWxrCommand::class))->toBeTrue()
        ->and($contributions)->toContain([
            'type' => 'console-command',
            'class' => WordPressImporterConsoleCommandContribution::class,
            'commands' => ['wordpress-importer:import'],
            'commandClasses' => [ImportWordPressWxrCommand::class],
        ])
        ->and($contributions)->toContain([
            'type' => 'health-check',
            'class' => WordpressImporterHealthCheck::class,
        ])
        ->and(data_get($manifest, 'healthChecks.0.class'))->toBe(WordpressImporterHealthCheck::class)
        ->and(class_implements(WordPressImporterConsoleCommandContribution::class))->toContain(ExtensionContribution::class)
        ->and(class_implements(WordpressImporterHealthCheck::class))->toContain(ChecksExtensionHealth::class)
        ->and(class_implements(WxrReader::class))->toContain(ImportSourceReader::class)
        ->and(data_get($manifest, 'capabilities', []))->toContain(
            'wordpress-wxr-reader',
            'wordpress-wxr-preview',
            'wordpress-permalink-redirects',
            'wordpress-headless-preview',
        )
        ->and(data_get($manifest, 'contributionTraceability.deferredContributions'))->toBe([]);
});

it('declares committed marketplace assets for required wordpress importer screenshot targets', function (): void {
    $packagePath = dirname(__DIR__, 2);
    $manifest = capell_json_file_array($packagePath . '/capell.json');
    $screenshotContract = capell_json_file_array($packagePath . '/docs/screenshots.json');

    $marketplaceScreenshots = data_get($manifest, 'marketplace.screenshots');
    $contractEntries = data_get($screenshotContract, 'entries');

    throw_unless(is_array($marketplaceScreenshots), RuntimeException::class, 'Expected WordPress Importer marketplace screenshots.');
    throw_unless(is_array($contractEntries), RuntimeException::class, 'Expected WordPress Importer screenshot contract entries.');

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
