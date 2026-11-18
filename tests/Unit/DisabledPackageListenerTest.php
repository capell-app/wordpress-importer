<?php

declare(strict_types=1);

use Capell\Core\Facades\CapellCore;
use Capell\MigrationAssistant\Enums\ImportSessionKind;
use Capell\MigrationAssistant\Enums\ImportSessionStatus;
use Capell\MigrationAssistant\Events\ImportCompleting;
use Capell\MigrationAssistant\Models\ImportSession;
use Capell\WordPressImporter\Listeners\CreateWordPressRedirectsForCompletingImport;
use Capell\WordPressImporter\Listeners\ImportWordPressMediaForCompletingImport;
use Capell\WordPressImporter\Providers\WordPressImporterServiceProvider;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function wordPressImporterCompletingSession(): ImportSession
{
    return ImportSession::query()->create([
        'uuid' => (string) Str::uuid(),
        'kind' => ImportSessionKind::PageImport,
        'status' => ImportSessionStatus::Completed,
        'source_filename' => 'export.xml',
        'source_package_path' => 'migration-assistant/imports/export.xml',
        'result_summary' => ['created_page_ids' => [987654]],
    ]);
}

it('records no permalink redirects while WordPress Importer is disabled, and records once re-enabled', function (): void {
    $session = wordPressImporterCompletingSession();

    CapellCore::markPackageDisabled(WordPressImporterServiceProvider::$packageName);
    (new CreateWordPressRedirectsForCompletingImport)->handle(new ImportCompleting($session));

    expect($session->refresh()->result_summary)->not->toHaveKey('wordpress_permalink_redirects');

    CapellCore::markPackageInstalled(WordPressImporterServiceProvider::$packageName);
    (new CreateWordPressRedirectsForCompletingImport)->handle(new ImportCompleting($session));

    expect($session->refresh()->result_summary)->toHaveKey('wordpress_permalink_redirects');
});

it('loads no imported pages for media while WordPress Importer is disabled, and loads them once re-enabled', function (): void {
    $session = wordPressImporterCompletingSession();
    $pageQueries = 0;
    DB::listen(static function (QueryExecuted $query) use (&$pageQueries): void {
        if (str_contains($query->sql, '"pages"')) {
            $pageQueries++;
        }
    });

    CapellCore::markPackageDisabled(WordPressImporterServiceProvider::$packageName);
    (new ImportWordPressMediaForCompletingImport)->handle(new ImportCompleting($session));

    expect($pageQueries)->toBe(0);

    CapellCore::markPackageInstalled(WordPressImporterServiceProvider::$packageName);
    (new ImportWordPressMediaForCompletingImport)->handle(new ImportCompleting($session));

    expect($pageQueries)->toBeGreaterThan(0);
});
