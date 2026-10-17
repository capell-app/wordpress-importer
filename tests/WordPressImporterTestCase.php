<?php

declare(strict_types=1);

namespace Capell\WordPressImporter\Tests;

use Capell\Core\Facades\CapellCore;
use Capell\MigrationAssistant\Tests\MigrationAssistantTestCase;
use Capell\UrlManager\Providers\UrlManagerServiceProvider;
use Capell\WordPressImporter\Providers\WordPressImporterServiceProvider;
use Override;

abstract class WordPressImporterTestCase extends MigrationAssistantTestCase
{
    #[Override]
    protected function getEnvironmentSetUp(mixed $app): void
    {
        parent::getEnvironmentSetUp($app);

        CapellCore::forcePackageInstalled(WordPressImporterServiceProvider::$packageName);
    }

    /** @return array<int, class-string> */
    #[Override]
    protected function getPackageProviders(mixed $app): array
    {
        return [
            ...parent::getPackageProviders($app),
            UrlManagerServiceProvider::class,
            WordPressImporterServiceProvider::class,
        ];
    }
}
