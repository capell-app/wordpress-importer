<?php

declare(strict_types=1);

use Capell\MigrationAssistant\Support\ImportSourceRegistry;
use Capell\WordPressImporter\Health\WordpressImporterHealthCheck;

it('passes when the WXR reader and XML parser are available', function (): void {
    expect(WordpressImporterHealthCheck::passed())->toBeTrue();

    $results = WordpressImporterHealthCheck::runDiagnostics();

    expect($results)->toHaveCount(3)
        ->and($results->pluck('passed')->all())->toBe([true, true, true]);
});

it('fails when the WXR reader is not registered with migration assistant', function (): void {
    $this->app->instance(ImportSourceRegistry::class, new ImportSourceRegistry);

    $check = new WordpressImporterHealthCheck;
    $result = $check->wxrReaderRegistrationCheck();

    expect($check->hasRegisteredWxrReader())->toBeFalse()
        ->and($result->passed)->toBeFalse()
        ->and($result->remediation)->not->toBeNull()
        ->and(WordpressImporterHealthCheck::passed())->toBeFalse();
});

it('reports the SimpleXML extension requirement', function (): void {
    $result = (new WordpressImporterHealthCheck)->simpleXmlExtensionCheck();

    expect($result->passed)->toBeTrue()
        ->and($result->message)->toContain('SimpleXML');
});

it('reports the migration assistant reader contract compatibility', function (): void {
    $result = (new WordpressImporterHealthCheck)->readerContractCheck();

    expect($result->passed)->toBeTrue()
        ->and($result->message)->toContain('Migration Assistant');
});
