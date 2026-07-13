<?php

declare(strict_types=1);

namespace Capell\WordPressImporter\Manifest;

use Capell\Core\Contracts\Extensions\ExtensionContribution;

final class WordPressImporterConsoleCommandContribution implements ExtensionContribution
{
    public static function compatibleCapellApiVersion(): string
    {
        return '^1.0';
    }
}
