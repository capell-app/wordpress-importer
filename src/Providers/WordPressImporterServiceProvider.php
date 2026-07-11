<?php

declare(strict_types=1);

namespace Capell\WordPressImporter\Providers;

use Capell\Core\Support\Packages\AbstractPackageServiceProvider;
use Capell\MigrationAssistant\Events\ImportCompleting;
use Capell\MigrationAssistant\Support\ImportSessionExecutorRegistry;
use Capell\MigrationAssistant\Support\ImportSourceRegistry;
use Capell\WordPressImporter\Console\Commands\ImportWordPressWxrCommand;
use Capell\WordPressImporter\Contracts\WordPressMediaHostResolver;
use Capell\WordPressImporter\Listeners\CreateWordPressRedirectsForCompletingImport;
use Capell\WordPressImporter\Listeners\ImportWordPressMediaForCompletingImport;
use Capell\WordPressImporter\Services\WxrReader;
use Capell\WordPressImporter\Support\DnsWordPressMediaHostResolver;
use Capell\WordPressImporter\Support\WordPressWxrSessionExecutor;
use Illuminate\Support\Facades\Event;
use Spatie\LaravelPackageTools\Package;

final class WordPressImporterServiceProvider extends AbstractPackageServiceProvider
{
    public static string $name = 'capell-wordpress-importer';

    public static string $packageName = 'capell-app/wordpress-importer';

    public function configurePackage(Package $package): void
    {
        $package
            ->name(self::$name)
            ->hasConfigFile('wordpress-importer')
            ->hasTranslations()
            ->hasCommand(ImportWordPressWxrCommand::class);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(WordPressMediaHostResolver::class, DnsWordPressMediaHostResolver::class);

        $this->app->afterResolving(
            ImportSourceRegistry::class,
            static function (ImportSourceRegistry $registry): void {
                $registry->register(new WxrReader, prepend: true);
            },
        );

        $this->app->afterResolving(
            ImportSessionExecutorRegistry::class,
            static function (ImportSessionExecutorRegistry $registry): void {
                $registry->register(resolve(WordPressWxrSessionExecutor::class), prepend: true);
            },
        );
    }

    public function packageBooted(): void
    {
        Event::listen(ImportCompleting::class, [ImportWordPressMediaForCompletingImport::class, 'handle']);
        Event::listen(ImportCompleting::class, [CreateWordPressRedirectsForCompletingImport::class, 'handle']);
    }
}
