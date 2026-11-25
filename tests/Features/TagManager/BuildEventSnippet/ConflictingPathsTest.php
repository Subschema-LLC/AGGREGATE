<?php

declare(strict_types=1);

namespace App\Tests\Features\TagManager\BuildEventSnippet;

use App\Features\TagManager\BuildEventSnippet\ConflictingPaths;
use PHPUnit\Framework\TestCase;

/** Paths that share a place in the push are both named and left out. */
final class ConflictingPathsTest extends TestCase
{
    public function test_nested_paths_are_both_named_and_dropped(): void
    {
        $places = [
            ['key' => 'x', 'alias' => 'one', 'path' => 'a.b', 'segments' => ['a', 'b']],
            ['key' => 'y', 'alias' => 'two', 'path' => 'a.b.c', 'segments' => ['a', 'b', 'c']],
            ['key' => 'z', 'alias' => 'three', 'path' => 'd', 'segments' => ['d']],
        ];

        [$kept, $attention] = (new ConflictingPaths())->split($places);

        self::assertCount(1, $kept);
        self::assertSame('z', $kept[0]['key']);
        self::assertCount(1, $attention);
        self::assertStringContainsString('`one`', $attention[0]);
        self::assertStringContainsString('`two`', $attention[0]);
    }

    public function test_separate_paths_are_kept(): void
    {
        $places = [
            ['key' => 'x', 'alias' => 'one', 'path' => 'a', 'segments' => ['a']],
            ['key' => 'y', 'alias' => 'two', 'path' => 'b', 'segments' => ['b']],
        ];

        [$kept, $attention] = (new ConflictingPaths())->split($places);

        self::assertCount(2, $kept);
        self::assertSame([], $attention);
    }
}
