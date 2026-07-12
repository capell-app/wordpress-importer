<?php

declare(strict_types=1);

namespace Capell\WordPressImporter\Support;

use Capell\WordPressImporter\Contracts\WordPressMediaHostResolver;

final class DnsWordPressMediaHostResolver implements WordPressMediaHostResolver
{
    /**
     * @return list<string>
     */
    public function resolve(string $host): array
    {
        $records = dns_get_record($host, DNS_A | DNS_AAAA);

        if ($records === false) {
            return [];
        }

        return array_values(collect($records)
            ->flatMap(static fn (array $record): array => array_values(array_filter([
                is_string($record['ip'] ?? null) ? $record['ip'] : null,
                is_string($record['ipv6'] ?? null) ? $record['ipv6'] : null,
            ], static fn (?string $address): bool => $address !== null && $address !== '')))
            ->unique()
            ->values()
            ->all());
    }
}
