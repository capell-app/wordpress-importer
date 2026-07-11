<?php

declare(strict_types=1);

namespace Capell\WordPressImporter\Support;

use Capell\MigrationAssistant\Contracts\ImportSessionExecutor;
use Capell\MigrationAssistant\Models\ImportSession;
use Capell\WordPressImporter\Actions\ExecuteWordPressSpoolSessionAction;
use Capell\WordPressImporter\Actions\ReauthorizeWordPressWxrSessionAction;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Storage;

final class WordPressWxrSessionExecutor implements ImportSessionExecutor
{
    public function supports(ImportSession $session): bool
    {
        return $session->source_environment === 'wordpress-wxr';
    }

    public function canRetry(ImportSession $session): bool
    {
        $manifest = is_array($session->manifest) ? $session->manifest : [];
        $wordpressManifest = is_array($manifest['wordpress_wxr'] ?? null) ? $manifest['wordpress_wxr'] : [];
        $diskName = $wordpressManifest['disk'] ?? config('migration-assistant.disk', 'local');
        $manifestPath = $session->source_package_path;

        return is_string($diskName)
            && $diskName !== ''
            && is_string($manifestPath)
            && $manifestPath !== ''
            && Storage::disk($diskName)->exists($manifestPath);
    }

    public function execute(ImportSession $session): void
    {
        $actor = auth()->user();
        ReauthorizeWordPressWxrSessionAction::run($session, $actor instanceof Authenticatable ? $actor : null);

        ExecuteWordPressSpoolSessionAction::run($session);
    }
}
