<?php

declare(strict_types=1);

namespace Capell\WordPressImporter\Health;

use Capell\Core\Contracts\Extensions\ChecksExtensionHealth;
use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\MigrationAssistant\Contracts\ImportSourceReader;
use Capell\MigrationAssistant\Support\ImportSourceRegistry;
use Capell\WordPressImporter\Services\WxrReader;
use Illuminate\Support\Collection;
use Throwable;

final class WordpressImporterHealthCheck implements ChecksExtensionHealth
{
    public static function compatibleCapellApiVersion(): string
    {
        return '^4.0';
    }

    /**
     * @return Collection<int, DoctorCheckResultData>
     */
    public static function runDiagnostics(): Collection
    {
        $check = new self;

        return collect([
            $check->simpleXmlExtensionCheck(),
            $check->wxrReaderRegistrationCheck(),
            $check->readerContractCheck(),
        ]);
    }

    public static function passed(): bool
    {
        return self::runDiagnostics()
            ->every(static fn (DoctorCheckResultData $result): bool => $result->passed);
    }

    public function simpleXmlExtensionCheck(): DoctorCheckResultData
    {
        $extensionLoaded = extension_loaded('simplexml');

        return new DoctorCheckResultData(
            label: (string) __('capell-wordpress-importer::health.simplexml.label'),
            passed: $extensionLoaded,
            message: $extensionLoaded
                ? (string) __('capell-wordpress-importer::health.simplexml.passed')
                : (string) __('capell-wordpress-importer::health.simplexml.failed'),
            remediation: $extensionLoaded
                ? null
                : (string) __('capell-wordpress-importer::health.simplexml.remediation'),
        );
    }

    public function wxrReaderRegistrationCheck(): DoctorCheckResultData
    {
        $registered = $this->hasRegisteredWxrReader();

        return new DoctorCheckResultData(
            label: (string) __('capell-wordpress-importer::health.reader.label'),
            passed: $registered,
            message: $registered
                ? (string) __('capell-wordpress-importer::health.reader.passed')
                : (string) __('capell-wordpress-importer::health.reader.failed'),
            remediation: $registered
                ? null
                : (string) __('capell-wordpress-importer::health.reader.remediation'),
        );
    }

    public function readerContractCheck(): DoctorCheckResultData
    {
        $implementedContracts = class_implements(WxrReader::class);
        $implementsContract = is_array($implementedContracts)
            && in_array(ImportSourceReader::class, $implementedContracts, true);

        return new DoctorCheckResultData(
            label: (string) __('capell-wordpress-importer::health.contract.label'),
            passed: $implementsContract,
            message: $implementsContract
                ? (string) __('capell-wordpress-importer::health.contract.passed')
                : (string) __('capell-wordpress-importer::health.contract.failed'),
            remediation: $implementsContract
                ? null
                : (string) __('capell-wordpress-importer::health.contract.remediation'),
        );
    }

    public function hasRegisteredWxrReader(): bool
    {
        try {
            $registry = resolve(ImportSourceRegistry::class);
        } catch (Throwable) {
            return false;
        }

        if (! $registry instanceof ImportSourceRegistry) {
            return false;
        }

        return collect($registry->readers())
            ->contains(static fn (ImportSourceReader $reader): bool => $reader instanceof WxrReader);
    }
}
