<?php

declare(strict_types=1);

namespace Capell\WordPressImporter\Providers;

use Capell\Core\Support\Packages\AbstractPackageServiceProvider;
use Capell\MigrationAssistant\Support\ImportSourceRegistry;
use Capell\WordPressImporter\Services\WxrReader;
use Spatie\LaravelPackageTools\Package;

class WordPressImporterServiceProvider extends AbstractPackageServiceProvider
{
    public static string $name = 'wordpress-importer';

    public static string $packageName = 'capell-app/wordpress-importer';

    public function configurePackage(Package $package): void
    {
        $package->name(self::$name);
    }

    public function packageRegistered(): void
    {
        $this->app->afterResolving(
            ImportSourceRegistry::class,
            static function (ImportSourceRegistry $registry): void {
                $registry->register(new WxrReader, prepend: true);
            },
        );
    }
}
