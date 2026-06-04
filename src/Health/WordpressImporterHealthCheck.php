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
            label: 'WordPress WXR XML parser',
            passed: $extensionLoaded,
            message: $extensionLoaded
                ? 'The SimpleXML extension required for WordPress WXR parsing is loaded.'
                : 'The SimpleXML extension required for WordPress WXR parsing is not loaded.',
            remediation: $extensionLoaded
                ? null
                : 'Install and enable the PHP SimpleXML extension before importing WordPress WXR exports.',
        );
    }

    public function wxrReaderRegistrationCheck(): DoctorCheckResultData
    {
        $registered = $this->hasRegisteredWxrReader();

        return new DoctorCheckResultData(
            label: 'WordPress WXR source reader',
            passed: $registered,
            message: $registered
                ? 'The WordPress WXR reader is registered with Migration Assistant for XML imports.'
                : 'The WordPress WXR reader is not registered with Migration Assistant.',
            remediation: $registered
                ? null
                : 'Ensure WordPressImporterServiceProvider is loaded after Migration Assistant and registers the WxrReader.',
        );
    }

    public function readerContractCheck(): DoctorCheckResultData
    {
        $implementsContract = is_subclass_of(WxrReader::class, ImportSourceReader::class);

        return new DoctorCheckResultData(
            label: 'Migration Assistant reader contract',
            passed: $implementsContract,
            message: $implementsContract
                ? 'The WordPress WXR reader implements the Migration Assistant source-reader contract.'
                : 'The WordPress WXR reader no longer implements the Migration Assistant source-reader contract.',
            remediation: $implementsContract
                ? null
                : 'Update WxrReader to implement the current Migration Assistant ImportSourceReader contract.',
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
