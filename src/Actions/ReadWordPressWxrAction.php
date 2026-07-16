<?php

declare(strict_types=1);

namespace Capell\WordPressImporter\Actions;

use Capell\WordPressImporter\Data\WordPressWxrReadData;
use Capell\WordPressImporter\Services\WxrReader;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

/**
 * @method static WordPressWxrReadData run(string $path)
 */
final class ReadWordPressWxrAction
{
    use AsFake;
    use AsObject;

    public function handle(string $path): WordPressWxrReadData
    {
        $resolvedPath = realpath($path);

        if ($resolvedPath === false) {
            throw new RuntimeException((string) __('capell-wordpress-importer::commands.import.path_missing', ['path' => $path]));
        }

        $reader = resolve(WxrReader::class);

        if (! $reader->supportsPath($resolvedPath)) {
            throw new RuntimeException((string) __('capell-wordpress-importer::commands.import.invalid_wxr', ['path' => $path]));
        }

        return new WordPressWxrReadData(
            path: $resolvedPath,
            readResult: $reader->read($resolvedPath),
        );
    }
}
