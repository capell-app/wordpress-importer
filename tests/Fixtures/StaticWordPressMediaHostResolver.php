<?php

declare(strict_types=1);

namespace Capell\WordPressImporter\Tests\Fixtures;

use Capell\WordPressImporter\Contracts\WordPressMediaHostResolver;

final readonly class StaticWordPressMediaHostResolver implements WordPressMediaHostResolver
{
    /**
     * @param  array<string, list<string>>  $addresses
     */
    public function __construct(private array $addresses) {}

    /**
     * @return list<string>
     */
    public function resolve(string $host): array
    {
        return $this->addresses[$host] ?? [];
    }
}
