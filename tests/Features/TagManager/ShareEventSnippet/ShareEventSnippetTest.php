<?php

declare(strict_types=1);

namespace App\Tests\Features\TagManager\ShareEventSnippet;

use App\Entities\TagManager\SnippetShare;
use App\Entities\TagManager\SnippetShares;
use App\Entities\TagManager\TagManagers;
use App\Features\TagManager\ReadSharedEventSnippet\ReadSharedEventSnippet;
use App\Features\TagManager\ShareEventSnippet\ShareEventSnippet;
use App\Features\TagManager\StopSharingEventSnippet\StopSharingEventSnippet;
use PHPUnit\Framework\TestCase;

/** Share links last the configured days, replace the tag's old link and close on stop. */
final class ShareEventSnippetTest extends TestCase
{
    private \DateTimeImmutable $now;
    private SnippetShares $shares;
    private TagManagers $tags;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-11-25T12:00:00+00:00');
        $this->shares = new class () implements SnippetShares {
            /** @var array<string, list<SnippetShare>> */
            public array $rows = [];
            public int $saves = 0;

            public function all(?string $siteId = null): array
            {
                return $this->rows[$siteId ?? ''] ?? [];
            }

            public function save(array $shares, ?string $siteId = null): void
            {
                $this->rows[$siteId ?? ''] = $shares;
                ++$this->saves;
            }

            public function lifetimeDays(): int
            {
                return 3;
            }
        };
        $this->tags = new class () implements TagManagers {
            public array $tags = [['id' => 'signup'], ['id' => 'checkout']];

            public function all(?string $siteId = null): array
            {
                return ['enabled' => true, 'variables' => ['plan' => 'signup.plan'], 'tags' => $this->tags];
            }

            public function save(array $settings, ?string $siteId = null): void
            {
            }
        };
    }

    public function testALinkOpensTheSavedTagUntilItExpires(): void
    {
        $link = $this->share()->share('site', 'signup', $this->now);

        self::assertMatchesRegularExpression(SnippetShare::TOKEN_PATTERN, $link->token);
        self::assertEquals($this->now->modify('+3 days'), $link->expires);
        self::assertSame(SnippetShare::hashOf($link->token), $this->shares->all('site')[0]->hash);
        $read = $this->read();
        self::assertSame(['id' => 'signup'], $read->open('site', $link->token, $this->now)['tag']);
        self::assertSame(['plan' => 'signup.plan'], $read->open('site', $link->token, $this->now)['variables']);
        self::assertNull($read->open(null, $link->token, $this->now), 'another configuration');
        self::assertNull($read->open('site', str_repeat('0', 48), $this->now));
        self::assertEquals($link->expires, $read->sharedUntil('site', 'signup', $this->now));
        self::assertNull($read->sharedUntil('site', 'checkout', $this->now));

        $later = $this->now->modify('+3 days');
        self::assertNull($read->open('site', $link->token, $later));
        self::assertNull($read->sharedUntil('site', 'signup', $later));
    }

    public function testANewLinkReplacesTheOldOneAndPrunesDeadLinks(): void
    {
        $first = $this->share()->share('site', 'signup', $this->now);
        $this->share()->share('site', 'checkout', $this->now);
        $this->tags->tags = [['id' => 'signup']];
        $second = $this->share()->share('site', 'signup', $this->now->modify('+1 day'));

        self::assertNull($this->read()->open('site', $first->token, $this->now));
        self::assertNotNull($this->read()->open('site', $second->token, $this->now));
        self::assertSame(['signup'], array_map(
            static fn (SnippetShare $share): string => $share->tagId,
            $this->shares->all('site'),
        ), 'the removed tag lost its link');
    }

    public function testOnlySavedTagsCanBeShared(): void
    {
        $this->expectExceptionMessage('Save this tag first.');

        $this->share()->share('site', 'draft', $this->now);
    }

    public function testStopClosesTheLinkAndSavesOnlyWhenSomethingChanged(): void
    {
        $link = $this->share()->share('site', 'signup', $this->now);
        $stop = new StopSharingEventSnippet($this->shares);
        $saves = $this->shares->saves;

        self::assertTrue($stop->stop('site', 'signup', $this->now));
        self::assertNull($this->read()->open('site', $link->token, $this->now));
        self::assertFalse($stop->stop('site', 'signup', $this->now));
        self::assertSame($saves + 1, $this->shares->saves);
    }

    public function testLifetimeIsAWholeNumberOfDaysWithinTheLimit(): void
    {
        self::assertSame(14, SnippetShare::days('14'));
        self::assertSame(SnippetShare::MAX_DAYS, SnippetShare::days(SnippetShare::MAX_DAYS));
        foreach ([0, 366, '1.5', '', 'week', null, -1, '0x10'] as $invalid) {
            try {
                SnippetShare::days($invalid);
                self::fail(var_export($invalid, true).' is not a lifetime.');
            } catch (\InvalidArgumentException) {
            }
        }
    }

    public function testStoredRowsRoundTripAndBadRowsAreDropped(): void
    {
        $share = SnippetShare::create('signup', 7, $this->now, str_repeat('ab', 24));

        self::assertEquals($share, SnippetShare::fromArray($share->toArray()));
        self::assertNull(SnippetShare::fromArray(['tag' => 'signup', 'hash' => 'short', 'expires' => 'x']));
        self::assertNull(SnippetShare::fromArray('signup'));
    }

    private function share(): ShareEventSnippet
    {
        return new ShareEventSnippet($this->shares, $this->tags);
    }

    private function read(): ReadSharedEventSnippet
    {
        return new ReadSharedEventSnippet($this->shares, $this->tags);
    }
}
