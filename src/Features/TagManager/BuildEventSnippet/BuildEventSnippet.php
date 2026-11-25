<?php

declare(strict_types=1);

namespace App\Features\TagManager\BuildEventSnippet;

/**
 * "Get developer snippet": turns one Send an Aggregate event tag with a data layer
 * trigger into the dataLayer.push a developer adds to the page.
 *
 * Each customData value that reads a variable becomes a marked placeholder at the
 * variable's path, labelled by the customData key. Fixed values are left out: the
 * tag sets them itself. A path through `event` and paths that share a place are
 * named under attention and left out; the rest of the push is still built.
 * Nothing is saved or sent.
 */
final class BuildEventSnippet
{
    private const DEFAULT_TYPE = 'text, a number, true or false';
    private const LATEST = 'may already be on the page from an earlier push';

    /**
     * @param array<string, mixed> $tag the tag as edited: type, trigger, event
     * @param array<string, string> $variables alias => data path
     * @param array<string, string> $types customData key => accepted type
     */
    public function build(array $tag, array $variables, array $types = []): EventSnippet
    {
        if (($tag['type'] ?? null) !== 'event') {
            return $this->reason('Choose the action Send an Aggregate event first.');
        }
        $trigger = is_array($tag['trigger'] ?? null) ? $tag['trigger'] : [];
        if (($trigger['type'] ?? null) !== 'data_layer') {
            return $this->reason('Set the trigger to a data layer event first.');
        }
        $name = $trigger['event'] ?? '';
        $valid = '/^[A-Za-z][A-Za-z0-9_.:-]{0,99}$/D';
        if (!is_string($name) || preg_match($valid, $name) !== 1) {
            return $this->reason('Add an event name to the trigger first.');
        }

        $attention = [];
        $places = $this->places($tag, $variables, $attention);
        [$places, $conflicts] = (new ConflictingPaths())->split($places);
        $attention = [...$attention, ...$conflicts];
        $tree = [];
        foreach ($places as $place) {
            $this->insert($tree, $place['segments'], [
                $place['key'],
                $types[$place['key']] ?? self::DEFAULT_TYPE,
            ]);
        }
        $lines = [
            '// Add this where the page makes the event. Replace each marked value.',
            'window.dataLayer = window.dataLayer || [];',
            'window.dataLayer.push({',
            "  event: '".$name."',",
            ...$this->members($tree, '  '),
            '});',
        ];

        return new EventSnippet(implode("\n", $lines)."\n", null, $attention);
    }

    private function reason(string $reason): EventSnippet
    {
        return new EventSnippet(null, $reason);
    }

    /**
     * @param list<string> $attention
     * @return list<array{key: string, alias: string, path: string, segments: list<mixed>}>
     */
    private function places(array $tag, array $variables, array &$attention): array
    {
        $data = $tag['event']['customData'] ?? [];
        $places = [];
        foreach (is_array($data) ? $data : [] as $key => $value) {
            if (!is_array($value) || !is_string($value['$var'] ?? null)) {
                continue;
            }
            $alias = $value['$var'];
            $path = $variables[$alias] ?? null;
            if (!is_string($path) || $path === '') {
                $attention[] = sprintf(
                    'Variable `%s` is not defined, so `%s` is left out. '
                    .'Add it under Variables.',
                    $alias,
                    $key,
                );
                continue;
            }
            preg_match_all('/[A-Za-z_$][A-Za-z0-9_$]*|[0-9]+/', $path, $found);
            $segments = array_map(
                static fn (string $s): int|string => ctype_digit($s) ? (int) $s : $s,
                $found[0],
            );
            if (($segments[0] ?? null) === 'event' || ($value['from'] ?? null) === 'event') {
                $attention[] = sprintf(
                    'Variable `%s` reads `%s`. The event key must stay the event name, '
                    .'so this value is left out. Read it from another path.',
                    $alias,
                    $path,
                );
                continue;
            }
            $places[] = [
                'key' => (string) $key, 'alias' => $alias, 'path' => $path,
                'segments' => $segments,
            ];
        }

        return $places;
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
            $lines = [...$lines, ...$this->render($node, $pad, $segment.': ')];
        }

        return $lines;
    }

    /** @return list<string> */
    private function render(array $node, string $pad, string $prefix): array
    {
        if (isset($node['leaf'])) {
            [$key, $type] = $node['leaf'];

            return [sprintf(
                "%s%s'<<REPLACE: %s, %s>>', // %s; %s",
                $pad,
                $prefix,
                $key,
                $type,
                $type,
                self::LATEST,
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
