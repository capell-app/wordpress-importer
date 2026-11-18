<?php

declare(strict_types=1);

namespace Capell\WordPressImporter\Actions;

use Capell\WordPressImporter\Data\WordPressGutenbergConversionData;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Turns Gutenberg block markup into plain semantic HTML. Block delimiters and the wrapper
 * markup of layout blocks are removed, layout blocks keep only their content, and blocks the
 * importer cannot express are kept as their exported HTML and named for the report.
 *
 * @method static WordPressGutenbergConversionData run(string $content)
 */
final class ConvertWordPressGutenbergBlocksAction
{
    use AsFake;
    use AsObject;

    private const string DELIMITER = '/<!--\s*(\/)?wp:([a-z][a-z0-9_-]*(?:\/[a-z][a-z0-9_-]*)?)(?:\s+(\{.*?\}))?\s*(\/)?-->/s';

    /** Blocks whose exported HTML is plain content the importer keeps. */
    private const array CONTENT_BLOCKS = [
        'core/paragraph', 'core/heading', 'core/list', 'core/list-item', 'core/quote', 'core/pullquote',
        'core/code', 'core/preformatted', 'core/verse', 'core/table', 'core/image', 'core/gallery',
        'core/html', 'core/freeform', 'core/classic', 'core/details', 'core/footnotes',
    ];

    /** Blocks that only arrange other blocks: wrapper markup goes, children stay. */
    private const array LAYOUT_BLOCKS = [
        'core/group', 'core/columns', 'core/column', 'core/cover', 'core/buttons', 'core/media-text',
        'core/row', 'core/stack', 'core/grid', 'core/post-content',
    ];

    /** Blocks that carry no content of their own. */
    private const array DISCARDED_BLOCKS = ['core/separator', 'core/spacer', 'core/more', 'core/nextpage'];

    public function handle(string $content): WordPressGutenbergConversionData
    {
        if (preg_match(self::DELIMITER, $content) !== 1) {
            return new WordPressGutenbergConversionData($content);
        }

        $tokens = $this->tokenise($content);
        $position = 0;
        $nodes = $this->parse($tokens, $position, null);
        $unsupported = [];
        $html = $this->render($nodes, $unsupported);

        return new WordPressGutenbergConversionData(trim($this->tidy($html)), array_values(array_unique($unsupported)));
    }

    /**
     * @return list<array{type: string, text?: string, name?: string, attrs?: array<string, mixed>}>
     */
    private function tokenise(string $content): array
    {
        $matches = [];
        preg_match_all(self::DELIMITER, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $tokens = [];
        $cursor = 0;

        foreach ($matches as $match) {
            [$whole, $offset] = $match[0];

            if ($offset > $cursor) {
                $tokens[] = ['type' => 'text', 'text' => substr($content, $cursor, $offset - $cursor)];
            }
            $cursor = $offset + strlen($whole);

            $attributes = isset($match[3][0]) && $match[3][0] !== '' ? json_decode($match[3][0], true) : [];
            $tokens[] = [
                'type' => ($match[1][0] ?? '') === '/' ? 'close' : (($match[4][0] ?? '') === '/' ? 'void' : 'open'),
                'name' => $this->qualified($match[2][0]),
                'attrs' => is_array($attributes) ? $attributes : [],
            ];
        }

        if ($cursor < strlen($content)) {
            $tokens[] = ['type' => 'text', 'text' => substr($content, $cursor)];
        }

        return $tokens;
    }

    /**
     * @param  list<array{type: string, text?: string, name?: string, attrs?: array<string, mixed>}>  $tokens
     * @return list<array<string, mixed>>
     */
    private function parse(array $tokens, int &$position, ?string $closing): array
    {
        $nodes = [];
        $count = count($tokens);

        while ($position < $count) {
            $token = $tokens[$position];
            $position++;

            if ($token['type'] === 'text') {
                $nodes[] = ['text' => $token['text'] ?? ''];
            } elseif ($token['type'] === 'void') {
                $nodes[] = ['name' => $token['name'] ?? '', 'attrs' => $token['attrs'] ?? [], 'children' => []];
            } elseif ($token['type'] === 'open') {
                $name = $token['name'] ?? '';
                $nodes[] = ['name' => $name, 'attrs' => $token['attrs'] ?? [], 'children' => $this->parse($tokens, $position, $name)];
            } elseif ($closing !== null && ($token['name'] ?? null) === $closing) {
                return $nodes;
            }
        }

        return $nodes;
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<string>  $unsupported
     */
    private function render(array $nodes, array &$unsupported): string
    {
        $html = '';

        foreach ($nodes as $node) {
            if (isset($node['text'])) {
                $html .= is_string($node['text']) ? $node['text'] : '';

                continue;
            }

            $name = is_string($node['name'] ?? null) ? $node['name'] : '';
            /** @var list<array<string, mixed>> $children */
            $children = is_array($node['children'] ?? null) ? $node['children'] : [];
            /** @var array<string, mixed> $attributes */
            $attributes = is_array($node['attrs'] ?? null) ? $node['attrs'] : [];

            $html .= match (true) {
                in_array($name, self::DISCARDED_BLOCKS, true) => '',
                in_array($name, self::LAYOUT_BLOCKS, true) => $this->renderLayout($children, $unsupported),
                $name === 'core/button' => $this->renderChildrenText($children),
                str_starts_with($name, 'core/embed') || in_array($name, ['core/video', 'core/audio', 'core/file'], true) => $this->renderMediaLink($name, $attributes, $children, $unsupported),
                in_array($name, self::CONTENT_BLOCKS, true) => $this->render($children, $unsupported),
                default => $this->renderUnsupported($name, $children, $unsupported),
            };
        }

        return $html;
    }

    /**
     * @param  list<array<string, mixed>>  $children
     * @param  list<string>  $unsupported
     */
    private function renderLayout(array $children, array &$unsupported): string
    {
        // Text directly inside a layout block is its wrapper markup, never content.
        return $this->render(array_values(array_filter($children, static fn (array $child): bool => ! isset($child['text']))), $unsupported);
    }

    /**
     * @param  list<array<string, mixed>>  $children
     */
    private function renderChildrenText(array $children): string
    {
        $html = '';
        foreach ($children as $child) {
            $html .= is_string($child['text'] ?? null) ? $child['text'] : '';
        }

        $anchor = preg_match('/<a\b[^>]*>.*?<\/a>/is', $html, $matches) === 1 ? $matches[0] : '';

        return $anchor === '' ? '' : '<p>' . $anchor . '</p>';
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $children
     * @param  list<string>  $unsupported
     */
    private function renderMediaLink(string $name, array $attributes, array $children, array &$unsupported): string
    {
        $url = $attributes['url'] ?? null;

        if (! is_string($url) || $url === '') {
            $html = '';
            foreach ($children as $child) {
                $html .= is_string($child['text'] ?? null) ? $child['text'] : '';
            }
            if (preg_match('/https?:\/\/[^\s<"]+/i', $html, $matches) === 1) {
                $url = $matches[0];
            }
        }

        if (! is_string($url) || $url === '') {
            $unsupported[] = $name;

            return '';
        }

        // The block cannot play inside imported content, so readers keep a link to the original.
        $unsupported[] = $name;
        $safe = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');

        return '<p><a href="' . $safe . '">' . $safe . '</a></p>';
    }

    /**
     * @param  list<array<string, mixed>>  $children
     * @param  list<string>  $unsupported
     */
    private function renderUnsupported(string $name, array $children, array &$unsupported): string
    {
        $unsupported[] = $name;

        return $this->render($children, $unsupported);
    }

    private function tidy(string $html): string
    {
        $html = preg_replace_callback('/\sclass="([^"]*)"/i', static function (array $matches): string {
            $kept = array_filter(
                preg_split('/\s+/', trim($matches[1])) ?: [],
                static fn (string $class): bool => $class !== '' && preg_match('/^(wp-|has-|is-|align|size-)/', $class) !== 1,
            );

            return $kept === [] ? '' : ' class="' . implode(' ', $kept) . '"';
        }, $html) ?? $html;

        return preg_replace("/\n{3,}/", "\n\n", $html) ?? $html;
    }

    private function qualified(string $name): string
    {
        return str_contains($name, '/') ? $name : 'core/' . $name;
    }
}
