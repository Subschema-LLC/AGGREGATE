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
        $attention = [...$attention, ...$this->dropConflicts($places)];
        $tree = new PlaceholderTree();
        foreach ($places as $place) {
            $type = $types[$place['key']] ?? self::DEFAULT_TYPE;
            $tree->add($place['segments'], $place['key'], $type);
        }
        $lines = [
            '// Add this where the page makes the event. Replace each marked value.',
            'window.dataLayer = window.dataLayer || [];',
            'window.dataLayer.push({',
            "  event: '".$name."',",
            ...$tree->lines('  '),
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

    /**
     * @param list<array{key: string, alias: string, path: string,
     *     segments: list<int|string>}> $places
     * @return list<string>
     */
    private function dropConflicts(array &$places): array
    {
        $attention = [];
        $drop = [];
        foreach ($places as $i => $a) {
            foreach ($places as $j => $b) {
                if ($j <= $i) {
                    continue;
                }
                $length = min(count($a['segments']), count($b['segments']));
                $shared = array_slice($a['segments'], 0, $length);
                if ($shared !== array_slice($b['segments'], 0, $length)) {
                    continue;
                }
                $drop[$i] = $drop[$j] = true;
                $attention[] = sprintf(
                    'Variables `%s` (%s) and `%s` (%s) use the same place in the push, '
                    .'so both are left out. The rest of the push is still built.',
                    $a['alias'],
                    $a['path'],
                    $b['alias'],
                    $b['path'],
                );
            }
        }
        $places = array_values(array_diff_key($places, $drop));

        return $attention;
    }
}
