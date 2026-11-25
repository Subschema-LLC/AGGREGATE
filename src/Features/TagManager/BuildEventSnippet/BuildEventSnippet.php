<?php

declare(strict_types=1);

namespace App\Features\TagManager\BuildEventSnippet;

/**
 * "Get developer snippet": turns one Send an Aggregate event tag with a data layer
 * trigger into the dataLayer.push a developer adds to the page.
 *
 * Each customData value that reads a variable becomes a marked placeholder at the
 * variable's path, labelled by the customData key. Fixed values are left out: the
 * tag sets them itself. A tag with no variables falls back to its property table:
 * each property is a placeholder named by its key, with a note that the tag still
 * sends its fixed values. A path through `event` and paths that share a place are
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
        if ($places === [] && $attention === []) {
            [$places, $attention] = $this->propertyTable($tag);
        }
        [$places, $conflicts] = (new ConflictingPaths())->split($places);
        $tree = new PlaceholderTree();
        foreach ($places as $place) {
            $type = $types[$place['key']] ?? $place['type'] ?? self::DEFAULT_TYPE;
            $tree->add($place['segments'], $place['key'], $type, $place['latest']);
        }
        $lines = [
            '// Add this where the page makes the event. Replace each marked value.',
            'window.dataLayer = window.dataLayer || [];',
            'window.dataLayer.push({',
            "  event: '".$name."',",
            ...$tree->lines('  '),
            '});',
        ];

        return new EventSnippet(implode("\n", $lines)."\n", null, [...$attention, ...$conflicts]);
    }

    private function reason(string $reason): EventSnippet
    {
        return new EventSnippet(null, $reason);
    }

    /**
     * @param list<string> $attention
     * @return list<array{key: string, alias: string, path: string,
     *     segments: list<int|string>, latest: bool}>
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
            if (($segments[0] ?? null) === 'event') {
                $attention[] = sprintf(
                    'Variable `%s` reads `%s`. The event key must stay the event name, '
                    .'so this value is left out. Read it from another path.',
                    $alias,
                    $path,
                );
                continue;
            }
            // "This event's value" reads the push itself; "latest value" may come
            // from an earlier push, which its comment says.
            $places[] = [
                'key' => (string) $key, 'alias' => $alias, 'path' => $path,
                'segments' => $segments, 'latest' => ($value['from'] ?? null) !== 'event',
            ];
        }

        return $places;
    }

    /**
     * The fallback for a tag with no variables: each property in its table is a
     * placeholder at its key read as a path (a dotted key nests), named by the key
     * and typed by its fixed value.
     *
     * @return array{0: list<array<string, mixed>>, 1: list<string>}
     */
    private function propertyTable(array $tag): array
    {
        $data = $tag['event']['customData'] ?? [];
        $places = [];
        foreach (is_array($data) ? $data : [] as $key => $value) {
            $key = (string) $key;
            $places[] = [
                'key' => $key, 'alias' => $key, 'path' => $key, 'latest' => false,
                'segments' => array_map(
                    static fn (string $s): int|string => ctype_digit($s) ? (int) $s : $s,
                    explode('.', $key),
                ),
                'type' => match (true) {
                    is_bool($value) => 'true or false',
                    is_int($value), is_float($value) => 'a number',
                    is_string($value) => 'text',
                    default => self::DEFAULT_TYPE,
                },
            ];
        }
        if ($places === []) {
            return [[], []];
        }

        return [$places, [
            'This tag reads no variables, so the snippet names its properties as an example. '
            .'The tag still sends its fixed values; to send what the page pushes, give each '
            .'property a variable with the path shown.',
        ]];
    }
}
