<?php

declare(strict_types=1);

namespace App\Features\TagManager\ShareEventSnippet;

use App\Entities\TagManager\SnippetShare;
use App\Entities\TagManager\SnippetShares;
use App\Entities\TagManager\TagManagers;

/**
 * "Share link": makes a read-only link to a saved tag's developer snippet, lasting
 * the operator's number of days. A tag has one link at a time, so a new link stops
 * the old one. Expired links and links to removed tags are dropped on the way.
 */
final class ShareEventSnippet
{
    public function __construct(
        private readonly SnippetShares $shares,
        private readonly TagManagers $tagManagers,
    ) {
    }

    public function share(?string $siteId, string $tagId, \DateTimeImmutable $now): SharedLink
    {
        $saved = array_column($this->tagManagers->all($siteId)['tags'], 'id');
        if ($tagId === '' || !in_array($tagId, $saved, true)) {
            throw new \InvalidArgumentException(
                'Save this tag first. A shared link shows the saved tag only.',
            );
        }
        $token = bin2hex(random_bytes(24));
        $share = SnippetShare::create($tagId, $this->shares->lifetimeDays(), $now, $token);
        $others = array_filter(
            SnippetShare::current($this->shares->all($siteId), $saved, $now),
            static fn (SnippetShare $old): bool => $old->tagId !== $tagId,
        );
        $this->shares->save([...array_values($others), $share], $siteId);

        return new SharedLink($token, $share->expires);
    }
}
