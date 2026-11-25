<?php

declare(strict_types=1);

namespace App\Tests\Delivery\Cli\TagManager;

use App\Delivery\Cli\TagManager\SnippetCommand;
use App\Features\TagManager\BuildEventSnippet\BuildEventSnippet;
use App\Features\TagManager\ShareEventSnippet\ShareEventSnippet;
use App\Features\TagManager\StopSharingEventSnippet\StopSharingEventSnippet;
use App\Integrations\TagManager\CustomDataSettingsProperties;
use App\Integrations\TagManager\PeastCustomCodeSyntax;
use App\Integrations\TagManager\YamlSnippetShares;
use App\Integrations\TagManager\YamlTagManagers;
use App\Service\AggregateConfigLoader;
use App\Service\FeatureFlags;
use App\Service\SiteScriptConfig;
use App\Service\TagManagerSettings;
use App\Service\WebsiteConfigManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

final class SnippetCommandTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/aggregate-snippet-cli-'.bin2hex(random_bytes(8));
        mkdir($this->projectDir.'/config', 0700, true);
        $this->writeConfig([]);
        file_put_contents($this->projectDir.'/config/websites.yaml', Yaml::dump(['websites' => [
            ['name' => 'First shop', 'domain' => 'first.example', 'token' => 'first-public-token'],
        ]]));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectDir);
    }

    public function testPrintsTheSavedTagsSnippetWithItsAttentionItems(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['tag' => 'signup']));
        $display = $tester->getDisplay();
        self::assertStringContainsString("event: 'signup',", $display);
        self::assertStringContainsString("plan: '<<REPLACE: plan, text", $display);
        self::assertStringNotContainsString('source', $display, 'fixed values are not asked of');
        self::assertStringNotContainsString('Needs attention:', $display, 'a page path, not this event');
        self::assertStringNotContainsString('Shared link', $display);
        self::assertStringNotContainsString('tag_snippet_shares', $this->configYaml(), 'nothing saved');
    }

    public function testUnknownTagAndTagsWithoutASnippetFail(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['tag' => 'missing']));
        self::assertStringContainsString('There is no tag "missing"', $tester->getDisplay());

        self::assertSame(Command::FAILURE, $tester->execute(['tag' => 'analytics']));
        self::assertStringNotContainsString('<<REPLACE', $tester->getDisplay());
        self::assertNotSame('', trim($tester->getDisplay()), 'the reason is printed');
    }

    public function testSharesAndStopsSharingALinkKeepingOnlyItsHash(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['tag' => 'signup', '--share' => true]));
        $pattern = '~Shared link: https://analytics\.example/shared-snippet/shared/([a-f0-9]{48})~';
        self::assertMatchesRegularExpression($pattern, $tester->getDisplay());
        preg_match($pattern, $tester->getDisplay(), $match);
        self::assertStringContainsString('Anyone with this link can read this snippet until', $tester->getDisplay());
        $yaml = $this->configYaml();
        self::assertStringNotContainsString($match[1], $yaml);
        self::assertStringContainsString(hash('sha256', $match[1]), $yaml);

        self::assertSame(Command::SUCCESS, $tester->execute(['tag' => 'signup', '--stop-sharing' => true]));
        self::assertStringContainsString('Sharing stopped', $tester->getDisplay());
        self::assertStringNotContainsString(hash('sha256', $match[1]), $this->configYaml());

        self::assertSame(Command::SUCCESS, $tester->execute(['tag' => 'signup', '--stop-sharing' => true]));
        self::assertStringContainsString('no open link', $tester->getDisplay());
    }

    public function testSharingNeedsTheDashboard(): void
    {
        $this->writeConfig(['dashboard_enabled' => false]);
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['tag' => 'signup', '--share' => true]));
        self::assertStringContainsString('dashboard is turned off', $tester->getDisplay());
        self::assertStringNotContainsString('tag_snippet_shares', $this->configYaml());
    }

    public function testReadsAWebsitesOwnTags(): void
    {
        $id = SiteScriptConfig::idForToken('first-public-token');
        $this->sites()->save($id, ['enabled' => true, 'tags' => [[
            'id' => 'checkout', 'type' => 'event', 'enabled' => true, 'consent' => 'analytics',
            'trigger' => ['type' => 'data_layer', 'event' => 'checkout'],
            'event' => ['eventName' => 'checkout', 'customData' => ['total' => 12]],
        ]]], ['enabled' => false]);
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['tag' => 'checkout']), 'not in the shared settings');
        self::assertSame(Command::SUCCESS, $tester->execute(['tag' => 'checkout', '--site' => $id]));
        self::assertStringContainsString("total: '<<REPLACE: total, a number", $tester->getDisplay());
        self::assertStringContainsString('reads no variables', $tester->getDisplay());
    }

    private function tester(): CommandTester
    {
        $config = new AggregateConfigLoader($this->projectDir, 'test');
        $props = new CustomDataSettingsProperties();
        $syntax = new PeastCustomCodeSyntax();
        $sites = $this->sites();
        $store = new YamlTagManagers($config, $props, $syntax, $sites);
        $settings = new TagManagerSettings($config, $sites, new FeatureFlags($config), $props, $syntax, $store);
        $shares = new YamlSnippetShares($config, $sites);

        return new CommandTester(new Command(null, new SnippetCommand(
            $settings,
            new BuildEventSnippet(),
            new ShareEventSnippet($shares, $store),
            new StopSharingEventSnippet($shares),
            $config,
        )));
    }

    private function sites(): SiteScriptConfig
    {
        return new SiteScriptConfig(
            new WebsiteConfigManager($this->projectDir),
            $this->projectDir,
            'test',
            properties: new CustomDataSettingsProperties(),
            syntax: new PeastCustomCodeSyntax(),
        );
    }

    private function writeConfig(array $extra): void
    {
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump($extra + [
            'app_host' => 'https://analytics.example/',
            'environments' => ['test' => ['tag_manager' => [
                'enabled' => true,
                'variables' => ['plan' => 'signup.plan'],
                'tags' => [
                    [
                        'id' => 'analytics', 'type' => 'script', 'enabled' => true,
                        'src' => 'https://scripts.example/analytics.js', 'consent' => 'analytics',
                        'trigger' => ['type' => 'dom_ready'],
                    ],
                    [
                        'id' => 'signup', 'type' => 'event', 'enabled' => true, 'consent' => 'analytics',
                        'trigger' => ['type' => 'data_layer', 'event' => 'signup'],
                        'event' => ['eventName' => 'signup', 'customData' => [
                            'plan' => ['$var' => 'plan'], 'source' => 'form',
                        ]],
                    ],
                ],
            ]]],
        ], 8, 2));
    }

    private function configYaml(): string
    {
        return (string) file_get_contents($this->projectDir.'/config/aggregate.yaml');
    }
}
