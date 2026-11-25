<?php

declare(strict_types=1);

namespace App\Features\TagManager\StopSharingEventSnippet;

use App\Entities\TagManager\SnippetShare;
use App\Entities\TagManager\SnippetShares;

/**
 * "Stop sharing": the tag's shared snippet link stops working at once. Answers
 * whether there was an open link to stop.
 */
final class StopSharingEventSnippet
{
    public function __construct(private readonly SnippetShares $shares)
    {
    }

    public function stop(?string $siteId, string $tagId, \DateTimeImmutable $now): bool
    {
        $open = false;
        $kept = [];
        $all = $this->shares->all($siteId);
        foreach ($all as $share) {
            if ($share->tagId !== $tagId) {
                $kept[] = $share;
            } elseif (!$share->isExpiredAt($now)) {
                $open = true;
            }
        }
        if (count($kept) !== count($all)) {
            $this->shares->save($kept, $siteId);
        }

        return $open;
    }
}
