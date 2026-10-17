<?php

declare(strict_types=1);

use Capell\Core\Actions\InstallPackageAction;
use Capell\Core\Actions\RuntimeRefresh\RefreshInstalledPackageRuntimeAction;
use Capell\Core\Facades\CapellCore;
use Capell\MigrationAssistant\Events\ImportCompleting;
use Capell\MigrationAssistant\Models\ImportSession;
use Capell\MigrationAssistant\Support\ImportSourceRegistry;
use Capell\Tests\Support\InstalledRuntimeParity;
use Capell\Tests\Support\InstalledRuntimeState;
use Capell\Tests\Support\InstalledRuntimeTestCase;
use Illuminate\Support\Facades\Event;

it('matches fresh installed boot after in-process installation and repeated refresh', function (): void {
    InstalledRuntimeParity::assertPackage('wordpress-importer');
});

it('retains source discovery early and adds completion reports only after installation', function (): void {
    InstalledRuntimeState::run(static function (): void {
        $app = InstalledRuntimeTestCase::boot(InstalledRuntimeParity::manifest('wordpress-importer'), false, sourceRoot: dirname(__DIR__, 3));
        expect($app->make(ImportSourceRegistry::class)->readerFor('preview.xml')->supports('xml'))->toBeTrue();
        $session = ImportSession::query()->create([
            'kind' => 'page-import',
            'result_summary' => ['created_page_ids' => [-1]],
        ]);
        Event::dispatch(new ImportCompleting($session));
        expect($session->refresh()->result_summary)->not->toHaveKey('wordpress_permalink_redirects');

        $package = CapellCore::getPackage('capell-app/wordpress-importer');
        InstalledRuntimeTestCase::prepareLateInstallMigrations($app);
        InstallPackageAction::run($package);
        RefreshInstalledPackageRuntimeAction::run($package);
        RefreshInstalledPackageRuntimeAction::run($package);
        Event::dispatch(new ImportCompleting($session));

        expect($session->refresh()->result_summary)->toHaveKey('wordpress_permalink_redirects');
    });
});
