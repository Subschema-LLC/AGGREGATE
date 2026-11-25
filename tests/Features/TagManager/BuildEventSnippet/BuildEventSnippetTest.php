<?php

declare(strict_types=1);

namespace App\Tests\Features\TagManager\BuildEventSnippet;

use App\Features\TagManager\BuildEventSnippet\BuildEventSnippet;
use PHPUnit\Framework\TestCase;

final class BuildEventSnippetTest extends TestCase
{
    /** @param array<string, mixed> $customData */
    private function tag(array $customData, string $event = 'purchase'): array
    {
        return [
            'type' => 'event',
            'trigger' => ['type' => 'data_layer', 'event' => $event],
            'event' => ['eventName' => 'buy', 'customData' => $customData],
        ];
    }

    public function testVariablePropertyBecomesLabelledPlaceholderAtItsPath(): void
    {
        $result = (new BuildEventSnippet())->build(
            $this->tag(['plan' => ['$var' => 'p'], 'method' => 'card']),
            ['p' => 'order.plan'],
            ['plan' => 'text'],
        );

        self::assertNull($result->reason);
        self::assertSame([], $result->attention);
        self::assertStringContainsString("event: 'purchase',", (string) $result->snippet);
        self::assertStringContainsString('order: {', (string) $result->snippet);
        self::assertStringContainsString("plan: '<<REPLACE: plan, text>>',", (string) $result->snippet);
        self::assertStringContainsString('earlier push', (string) $result->snippet);
        self::assertStringNotContainsString('card', (string) $result->snippet);
    }

    public function testPathThroughEventIsLeftOutAndReported(): void
    {
        $result = (new BuildEventSnippet())->build(
            $this->tag(['plan' => ['$var' => 'p'], 'id' => ['$var' => 'i']]),
            ['p' => 'event.detail.plan', 'i' => 'order.id'],
        );

        self::assertCount(1, $result->attention);
        self::assertStringContainsString('`p` reads `event.detail.plan`', $result->attention[0]);
        self::assertStringNotContainsString('detail', (string) $result->snippet);
        self::assertStringContainsString('<<REPLACE: id,', (string) $result->snippet);
    }

    public function testConflictingPathsNameBothAndKeepTheRest(): void
    {
        $result = (new BuildEventSnippet())->build(
            $this->tag([
                'a' => ['$var' => 'x'], 'b' => ['$var' => 'y'], 'c' => ['$var' => 'z'],
            ]),
            ['x' => 'a.b', 'y' => 'a.b.c', 'z' => 'other'],
        );

        self::assertCount(1, $result->attention);
        self::assertStringContainsString('`x`', $result->attention[0]);
        self::assertStringContainsString('`y`', $result->attention[0]);
        self::assertStringContainsString('<<REPLACE: c,', (string) $result->snippet);
        self::assertStringNotContainsString('<<REPLACE: a,', (string) $result->snippet);
    }

    public function testIndexedPathBuildsAnArray(): void
    {
        $result = (new BuildEventSnippet())->build(
            $this->tag(['sku' => ['$var' => 's']]),
            ['s' => 'items[0].sku'],
        );

        self::assertStringContainsString('items: [', (string) $result->snippet);
    }

    public function testMissingEventNameGivesAReasonAndNoSnippet(): void
    {
        $result = (new BuildEventSnippet())->build($this->tag([], ''), []);

        self::assertNull($result->snippet);
        self::assertSame('Add an event name to the trigger first.', $result->reason);
    }

    public function testTagWithoutVariablesPushesOnlyTheEvent(): void
    {
        $result = (new BuildEventSnippet())->build($this->tag(['method' => 'card']), []);

        self::assertStringNotContainsString('REPLACE', (string) $result->snippet);
        self::assertStringContainsString("event: 'purchase',", (string) $result->snippet);
    }
}
