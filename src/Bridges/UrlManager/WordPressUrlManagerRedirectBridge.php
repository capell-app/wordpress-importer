<?php

declare(strict_types=1);

namespace Capell\WordPressImporter\Bridges\UrlManager;

use Capell\Core\Facades\CapellCore;
use Capell\UrlManager\Actions\UpsertRedirectRuleAction;
use Capell\UrlManager\Data\RedirectRuleData;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/** Creates WordPress permalink redirects in URL Manager. */
final class WordPressUrlManagerRedirectBridge
{
    public static function upsert(string $oldPermalink, string $targetUrl, ?int $siteId, ?int $languageId, string $notes, ?int $createdByUserId): Model
    {
        if (! CapellCore::isPackageAvailable('capell-app/url-manager')) {
            throw new RuntimeException('URL Manager must be installed before creating WordPress redirects.');
        }

        return UpsertRedirectRuleAction::run(new RedirectRuleData(
            sourceUrl: $oldPermalink,
            targetUrl: $targetUrl,
            siteId: $siteId,
            languageId: $languageId,
            notes: $notes,
            createdByUserId: $createdByUserId,
        ), replaceExisting: false);
    }
}
