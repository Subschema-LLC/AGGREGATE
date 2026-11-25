<?php

declare(strict_types=1);

namespace App\Delivery\Cli\TagManager;

use App\Features\TagManager\BuildEventSnippet\BuildEventSnippet;
use App\Service\TagManagerSettings;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prints the developer snippet of a saved tag, with the same text and the same
 * attention items as the dashboard's "Get developer snippet" button. Reads the
 * saved tag only; nothing is changed or sent.
 */
#[AsCommand(
    name: 'app:tag-manager:snippet',
    description: 'Print the developer snippet for a saved data layer event tag',
)]
final class SnippetCommand
{
    public function __construct(
        private readonly TagManagerSettings $settings,
        private readonly BuildEventSnippet $builder,
    ) {
    }

    public function __invoke(
        OutputInterface $output,
        #[Argument('The tag ID')]
        string $tag,
        #[Option('The website script ID; leave out for the shared configuration')]
        string $site = '',
    ): int {
        try {
            $saved = $this->settings->all($site !== '' ? $site : null);
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

        $result = $this->builder->build($found, $saved['variables'] ?? []);
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

        return Command::SUCCESS;
    }
}
