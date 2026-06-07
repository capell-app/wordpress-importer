<?php

declare(strict_types=1);

namespace Capell\WordPressImporter\Providers;

use Capell\Core\Support\Packages\AbstractPackageServiceProvider;
use Capell\MigrationAssistant\Events\ImportCompleted;
use Capell\MigrationAssistant\Support\ImportSourceRegistry;
use Capell\WordPressImporter\Console\Commands\ImportWordPressWxrCommand;
use Capell\WordPressImporter\Listeners\CreateWordPressRedirectsForCompletedImport;
use Capell\WordPressImporter\Listeners\ImportWordPressMediaForCompletedImport;
use Capell\WordPressImporter\Services\WxrReader;
use Illuminate\Support\Facades\Event;
use Spatie\LaravelPackageTools\Package;

class WordPressImporterServiceProvider extends AbstractPackageServiceProvider
{
    public static string $name = 'capell-wordpress-importer';

    public static string $packageName = 'capell-app/wordpress-importer';

    public function configurePackage(Package $package): void
    {
        $package
            ->name(self::$name)
            ->hasTranslations()
            ->hasCommand(ImportWordPressWxrCommand::class);
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

    public function packageBooted(): void
    {
        Event::listen(ImportCompleted::class, [CreateWordPressRedirectsForCompletedImport::class, 'handle']);
        Event::listen(ImportCompleted::class, [ImportWordPressMediaForCompletedImport::class, 'handle']);
    }
}
