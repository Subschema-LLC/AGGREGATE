<?php

declare(strict_types=1);

namespace App\Features\TagManager\BuildEventSnippet;

/**
 * The marked placeholders of a developer snippet, nested at their variables'
 * paths, and the dataLayer.push members that place them. A run of numeric
 * keys from 0 becomes an array; any other keys become an object. A key that is
 * not a JavaScript name is quoted. Only latest-value placeholders say they may
 * already be on the page.
 */
final class PlaceholderTree
{
    private const LATEST = 'may already be on the page from an earlier push';

    /** @var array<int|string, mixed> */
    private array $tree = [];

    /** @param list<int|string> $segments */
    public function add(array $segments, string $key, string $type, bool $latest): void
    {
        $this->insert($this->tree, $segments, [$key, $type, $latest]);
    }

    /** @return list<string> */
    public function lines(string $pad): array
    {
        return $this->members($this->tree, $pad);
    }

    /** @param array<int|string, mixed> $tree @param list<int|string> $segments */
    private function insert(array &$tree, array $segments, array $leaf): void
    {
        $segment = array_shift($segments);
        if ($segments === []) {
            $tree[$segment] = ['leaf' => $leaf];

            return;
        }
        $tree[$segment] ??= ['children' => []];
        $this->insert($tree[$segment]['children'], $segments, $leaf);
    }

    /** @return list<string> */
    private function members(array $tree, string $pad): array
    {
        $lines = [];
        foreach ($tree as $segment => $node) {
            $name = preg_match('/^[A-Za-z_$][A-Za-z0-9_$]*$/D', (string) $segment) === 1
                || is_int($segment) ? (string) $segment : "'".$segment."'";
            $lines = [...$lines, ...$this->render($node, $pad, $name.': ')];
        }

        return $lines;
    }

    /** @return list<string> */
    private function render(array $node, string $pad, string $prefix): array
    {
        if (isset($node['leaf'])) {
            [$key, $type, $latest] = $node['leaf'];

            return [sprintf(
                "%s%s'<<REPLACE: %s, %s>>', // %s%s",
                $pad,
                $prefix,
                $key,
                $type,
                $type,
                $latest ? '; '.self::LATEST : '',
            )];
        }
        $children = $node['children'];
        if (array_keys($children) === array_keys(array_fill(0, count($children), 0))) {
            return [$pad.$prefix.'[', ...$this->elements($children, $pad.'  '), $pad.'],'];
        }

        return [$pad.$prefix.'{', ...$this->members($children, $pad.'  '), $pad.'},'];
    }

    /** @return list<string> */
    private function elements(array $children, string $pad): array
    {
        $lines = [];
        foreach ($children as $node) {
            $lines = [...$lines, ...$this->render($node, $pad, '')];
        }

        return $lines;
    }
}
