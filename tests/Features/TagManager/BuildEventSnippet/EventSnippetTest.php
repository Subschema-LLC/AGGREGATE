<?php

declare(strict_types=1);

namespace App\Tests\Features\TagManager\BuildEventSnippet;

use App\Features\TagManager\BuildEventSnippet\EventSnippet;
use PHPUnit\Framework\TestCase;

/** The dashboard answer and the command line print the same three parts. */
final class EventSnippetTest extends TestCase
{
    public function test_the_answer_carries_snippet_reason_and_attention(): void
    {
        $answer = (new EventSnippet('code', null, ['note']))->toArray();

        self::assertSame(
            ['snippet' => 'code', 'reason' => null, 'attention' => ['note']],
            $answer,
        );
    }
}
