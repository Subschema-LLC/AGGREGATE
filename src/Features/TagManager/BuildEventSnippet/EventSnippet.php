<?php

declare(strict_types=1);

namespace App\Features\TagManager\BuildEventSnippet;

/**
 * What the developer handoff shows for one tag: the snippet, or the reason there
 * is none, plus the items that need attention. The dashboard and the command
 * line print these same texts.
 */
final class EventSnippet
{
    /** @param list<string> $attention */
    public function __construct(
        public readonly ?string $snippet,
        public readonly ?string $reason,
        public readonly array $attention = [],
    ) {
    }

    /** @return array{snippet: ?string, reason: ?string, attention: list<string>} */
    public function toArray(): array
    {
        return [
            'snippet' => $this->snippet,
            'reason' => $this->reason,
            'attention' => $this->attention,
        ];
    }
}
