<?php

declare(strict_types=1);

namespace Capell\WordPressImporter\Data;

final readonly class WordPressGutenbergConversionData
{
    /**
     * @param  list<string>  $unsupportedBlocks  Block names whose output could only be kept as the exported HTML.
     */
    public function __construct(
        public string $html,
        public array $unsupportedBlocks = [],
    ) {}
}
