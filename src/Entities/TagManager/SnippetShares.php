<?php

declare(strict_types=1);

namespace App\Entities\TagManager;

/**
 * Storage contract for shared snippet links, kept beside the tags they share
 * (a website's tags or the shared configuration), and the operator's setting
 * for how long a new link lasts.
 */
interface SnippetShares
{
    /** @return list<SnippetShare> */
    public function all(?string $siteId = null): array;

    /** @param list<SnippetShare> $shares replaces every link of that configuration */
    public function save(array $shares, ?string $siteId = null): void;

    /** Days a new link lasts: SnippetShare::SETTING, or SnippetShare::DEFAULT_DAYS. */
    public function lifetimeDays(): int;
}
