<?php

declare(strict_types=1);

namespace Capell\WordPressImporter\Contracts;

/**
 * @deprecated Outbound requests now resolve hosts through Core's
 *             Capell\Core\Contracts\Security\ResolvesHostAddresses, which the
 *             guarded HTTP client uses to check and pin every address. Bind that
 *             contract instead; this one is no longer consulted for requests.
 */
interface WordPressMediaHostResolver
{
    /**
     * @return list<string>
     */
    public function resolve(string $host): array;
}
