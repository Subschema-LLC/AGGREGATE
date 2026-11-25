<?php

declare(strict_types=1);

namespace App\Features\TagManager\ShareEventSnippet;

/**
 * A link just made: its token, shown this once and never stored, and when it
 * stops working.
 */
final class SharedLink
{
    public function __construct(
        public readonly string $token,
        public readonly \DateTimeImmutable $expires,
    ) {
    }
}
