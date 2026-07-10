<?php

declare(strict_types=1);

namespace Capell\WordPressImporter\Actions;

use Capell\MigrationAssistant\Actions\Imports\AuthorizeExternalPageImportTargetAction;
use Capell\MigrationAssistant\Data\ExternalPageImportTargetData;
use Capell\MigrationAssistant\Exceptions\ImportExecutionAuthorizationException;
use Capell\MigrationAssistant\Models\ImportSession;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use RuntimeException;

final class ReauthorizeWordPressWxrSessionAction
{
    public function handle(ImportSession $session, ?Authenticatable $actor): ExternalPageImportTargetData
    {
        $manifest = is_array($session->manifest) ? $session->manifest : [];
        $wordpressManifest = is_array($manifest['wordpress_wxr'] ?? null) ? $manifest['wordpress_wxr'] : [];
        $attributes = is_array($wordpressManifest['default_page_attributes'] ?? null)
            ? $wordpressManifest['default_page_attributes']
            : [];

        try {
            return AuthorizeExternalPageImportTargetAction::run(
                new ExternalPageImportTargetData(
                    siteId: $this->requiredId($attributes, 'site_id'),
                    layoutId: $this->requiredId($attributes, 'layout_id'),
                    blueprintId: $this->requiredId($attributes, 'blueprint_id'),
                    languageId: $this->requiredId($attributes, 'language_id'),
                ),
                $actor,
            );
        } catch (AuthorizationException|RuntimeException $exception) {
            throw new ImportExecutionAuthorizationException($exception->getMessage(), previous: $exception);
        }
    }

    /** @param array<string, mixed> $attributes */
    private function requiredId(array $attributes, string $key): int
    {
        $value = $attributes[$key] ?? null;

        if (! is_numeric($value) || (int) $value <= 0) {
            throw new RuntimeException((string) __('migration-assistant::imports.execution_target_missing'));
        }

        return (int) $value;
    }
}
