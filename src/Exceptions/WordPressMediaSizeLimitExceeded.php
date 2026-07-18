<?php

declare(strict_types=1);

namespace Capell\WordPressImporter\Exceptions;

use RuntimeException;

final class WordPressMediaSizeLimitExceeded extends RuntimeException
{
    public function __construct(public readonly int $bytes)
    {
        parent::__construct('WordPress media download exceeded the configured size limit.');
    }
}
