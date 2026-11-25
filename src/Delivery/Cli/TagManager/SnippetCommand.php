<?php

declare(strict_types=1);

namespace App\Delivery\Cli\TagManager;

use App\Delivery\Web\TagManager\SnippetRoutes;
use App\Features\TagManager\BuildEventSnippet\BuildEventSnippet;
use App\Features\TagManager\ShareEventSnippet\ShareEventSnippet;
use App\Features\TagManager\StopSharingEventSnippet\StopSharingEventSnippet;
use App\Service\AggregateConfigLoader;
use App\Service\TagManagerSettings;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prints the developer snippet of a saved tag, with the same text and the same
 * attention items as the dashboard's "Get developer snippet" button. --share makes
 * the read-only link the panel's "Share link" makes, and --stop-sharing closes it.
 */
#[AsCommand(
    name: 'app:tag-manager:snippet',
    description: 'Print, share or stop sharing the developer snippet of a saved event tag',
)]
final class SnippetCommand
{
    public function __construct(
        private readonly TagManagerSettings $settings,
        private readonly BuildEventSnippet $builder,
        private readonly ShareEventSnippet $share,
        private readonly StopSharingEventSnippet $stop,
        private readonly AggregateConfigLoader $config,
    ) {
    }

    public function __invoke(
        OutputInterface $output,
        #[Argument('The tag ID')]
        string $tag,
        #[Option('The website script ID; leave out for the shared configuration')]
        string $site = '',
        #[Option('Make a read-only link to the snippet, lasting tag_snippet_share_days')]
        bool $share = false,
        #[Option('Close the tag\'s shared link at once')]
        bool $stopSharing = false,
    ): int {
        $siteId = $site !== '' ? $site : null;
        try {
            $saved = $this->settings->all($siteId);
        } catch (\Throwable) {
            $output->writeln('The script settings could not be read. Check the YAML.');

            return Command::FAILURE;
        }
        $found = null;
        foreach ($saved['tags'] as $candidate) {
            if (($candidate['id'] ?? null) === $tag) {
                $found = $candidate;
                break;
            }
        }
        if ($found === null) {
            $output->writeln(sprintf('There is no tag "%s" in these settings.', $tag));

            return Command::FAILURE;
        }
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if ($stopSharing) {
            $output->writeln($this->stop->stop($siteId, $tag, $now)
                ? 'Sharing stopped. The link no longer opens.'
                : 'This tag had no open link.');

            return Command::SUCCESS;
        }

        $result = $this->builder->build($found, $saved['variables']);
        if ($result->snippet === null) {
            $output->writeln((string) $result->reason);

            return Command::FAILURE;
        }
        $output->write($result->snippet);
        if ($result->attention !== []) {
            $output->writeln('');
            $output->writeln('Needs attention:');
            foreach ($result->attention as $item) {
                $output->writeln('- '.$item);
            }
        }

        return $share ? $this->shareLink($output, $siteId, $tag, $now) : Command::SUCCESS;
    }

    private function shareLink(
        OutputInterface $output,
        ?string $siteId,
        string $tag,
        \DateTimeImmutable $now,
    ): int {
        if (!$this->config->isDashboardEnabled()) {
            $output->writeln('The dashboard is turned off, so a shared page cannot open.');

            return Command::FAILURE;
        }
        $link = $this->share->share($siteId, $tag, $now);
        $host = (string) $this->config->getWithEnvFallback('app_host', 'http://localhost:8000');
        $host = rtrim($host, '/');
        $output->writeln('');
        $output->writeln('Shared link: '.$host.SnippetRoutes::sharedPath($siteId, $link->token));
        $output->writeln(sprintf(
            'Anyone with this link can read this snippet until %s UTC. '
            .'It shows the saved tag only.',
            $link->expires->format('j M Y H:i'),
        ));

        return Command::SUCCESS;
    }
}
