<?php

declare(strict_types=1);

namespace Capell\WordPressImporter\Contracts;

interface WordPressMediaHostResolver
{
    /**
     * @return list<string>
     */
    public function resolve(string $host): array;
}
