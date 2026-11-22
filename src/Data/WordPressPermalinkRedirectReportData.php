<?php

declare(strict_types=1);

namespace Capell\WordPressImporter\Data;

use Capell\Core\Facades\CapellCore;

final readonly class WordPressPermalinkRedirectReportData
{
    /**
     * @param  list<int|string>  $createdRedirectRuleIds
     * @param  list<array{page_id: int|string|null, old_url: string|null, target_url: string|null, status: string, reason: string|null, redirect_rule_id: int|string|null, site_id: int|null, language_id: int|null}>  $redirects
     */
    public function __construct(
        public int $created,
        public int $updated,
        public int $skipped,
        public array $createdRedirectRuleIds,
        public array $redirects,
    ) {}

    public static function empty(): self
    {
        return new self(
            created: 0,
            updated: 0,
            skipped: 0,
            createdRedirectRuleIds: [],
            redirects: [],
        );
    }

    /**
     * @return list<array{class: class-string, id: int|string}>
     */
    public function createdModels(): array
    {
        if (! CapellCore::isPackageAvailable('capell-app/url-manager')) {
            return [];
        }

        /** @var class-string $redirectRuleModel */
        $redirectRuleModel = 'Capell\\UrlManager\\Models\\RedirectRule';

        return array_map(
            static fn (int|string $id): array => [
                'class' => $redirectRuleModel,
                'id' => $id,
            ],
            $this->createdRedirectRuleIds,
        );
    }

    /**
     * @return array{created: int, updated: int, skipped: int, created_redirect_rule_ids: list<int|string>, redirects: list<array{page_id: int|string|null, old_url: string|null, target_url: string|null, status: string, reason: string|null, redirect_rule_id: int|string|null, site_id: int|null, language_id: int|null}>}
     */
    public function toArray(): array
    {
        return [
            'created' => $this->created,
            'updated' => $this->updated,
            'skipped' => $this->skipped,
            'created_redirect_rule_ids' => $this->createdRedirectRuleIds,
            'redirects' => $this->redirects,
        ];
    }
}
