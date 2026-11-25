<?php

declare(strict_types=1);

namespace App\Integrations\TagManager;

use App\Entities\TagManager\SnippetShare;
use App\Entities\TagManager\SnippetShares;
use App\Service\AggregateConfigLoader;
use App\Service\SiteScriptConfig;

/**
 * Keeps shared snippet links under `tag_snippet_shares` in the YAML file that holds
 * their tags: a website's config/tag-manager/sites/<id>.yaml, or config/aggregate.yaml
 * for the shared configuration. Those files are already kept by updates, copied to
 * cluster servers and left out of Git, so a link works on every server. How long a
 * new link lasts is `tag_snippet_share_days` in config/aggregate.yaml.
 */
final class YamlSnippetShares implements SnippetShares
{
    private const KEY = 'tag_snippet_shares';

    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly ?SiteScriptConfig $sites = null,
    ) {
    }

    public function all(?string $siteId = null): array
    {
        $config = $this->configuration($siteId);
        $config->assertHealthy();
        $rows = $config->all()[self::KEY] ?? [];

        return array_values(array_filter(array_map(
            static fn (mixed $row): ?SnippetShare => SnippetShare::fromArray($row),
            is_array($rows) ? $rows : [],
        )));
    }

    public function save(array $shares, ?string $siteId = null): void
    {
        $config = $this->configuration($siteId);
        if ($siteId !== null && $this->sites !== null) {
            $this->sites->ensureDirectory();
        }
        $rows = array_map(static fn (SnippetShare $share): array => $share->toArray(), $shares);
        $config->updateMany(static fn (array $current): array => [self::KEY => $rows]);
    }

    public function lifetimeDays(): int
    {
        try {
            return SnippetShare::days(
                $this->config->get(SnippetShare::SETTING, SnippetShare::DEFAULT_DAYS),
            );
        } catch (\InvalidArgumentException) {
            // A hand-edited value the settings would refuse: the default, not a longer link.
            return SnippetShare::DEFAULT_DAYS;
        }
    }

    private function configuration(?string $siteId): AggregateConfigLoader
    {
        if ($siteId === null) {
            return $this->config;
        }
        if ($this->sites === null) {
            throw new \InvalidArgumentException('Website script configuration is unavailable.');
        }

        return $this->sites->configuration($siteId);
    }
}
