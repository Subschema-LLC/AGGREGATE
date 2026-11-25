<?php

declare(strict_types=1);

namespace App\Features\TagManager\BuildEventSnippet;

/**
 * Finds variables whose data paths share a place in the push (a.b and a.b.c), so a
 * snippet cannot hold both. Both are named and left out; the rest is still built.
 */
final class ConflictingPaths
{
    /**
     * @param list<array<string, mixed>> $places key, alias, path and segments each
     * @return array{0: list<array<string, mixed>>, 1: list<string>}
     *     the places that do not conflict, and one attention line per conflict
     */
    public function split(array $places): array
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

        return [array_values(array_diff_key($places, $drop)), $attention];
    }
}
