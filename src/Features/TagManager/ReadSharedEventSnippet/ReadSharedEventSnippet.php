<?php

declare(strict_types=1);

namespace App\Features\TagManager\ReadSharedEventSnippet;

use App\Entities\TagManager\SnippetShare;
use App\Entities\TagManager\SnippetShares;
use App\Entities\TagManager\TagManagers;

/**
 * Opens a shared snippet link: the saved tag it shares and when the link
 * expires, or null when the token opens nothing (unknown, expired, stopped or
 * its tag removed). Also says until when a tag is shared, for the editor.
 */
final class ReadSharedEventSnippet
{
    public function __construct(
        private readonly SnippetShares $shares,
        private readonly TagManagers $tagManagers,
    ) {
    }

    /**
     * @return array{tag: array<string, mixed>, variables: array<string, string>,
     *     expires: \DateTimeImmutable}|null
     */
    public function open(?string $siteId, string $token, \DateTimeImmutable $now): ?array
    {
        $saved = $this->tagManagers->all($siteId);
        foreach ($this->shares->all($siteId) as $share) {
            if (!$share->opensWith($token) || $share->isExpiredAt($now)) {
                continue;
            }
            foreach ($saved['tags'] as $tag) {
                if (($tag['id'] ?? null) === $share->tagId) {
                    return [
                        'tag' => $tag,
                        'variables' => $saved['variables'],
                        'expires' => $share->expires,
                    ];
                }
            }
        }

        return null;
    }

    public function sharedUntil(
        ?string $siteId,
        string $tagId,
        \DateTimeImmutable $now,
    ): ?\DateTimeImmutable {
        foreach ($this->shares->all($siteId) as $share) {
            if ($share->tagId === $tagId && !$share->isExpiredAt($now)) {
                return $share->expires;
            }
        }

        return null;
    }
}
