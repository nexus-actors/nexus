<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Tests\Unit\Membership;

use DateTimeImmutable;
use Monadial\Nexus\Cluster\Tcp\Membership\MembershipEventDeduplicator;
use Monadial\Nexus\Runtime\Duration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MembershipEventDeduplicator::class)]
final class MembershipEventDeduplicatorTest extends TestCase
{
    private const string PEER = '/cluster/prod/eu/app/node-2';

    private MembershipEventDeduplicator $dedup;

    private DateTimeImmutable $t0;

    #[Test]
    public function firstAnnouncementAlwaysPublishes(): void
    {
        self::assertTrue($this->dedup->shouldPublish(self::PEER, 1, 'up', $this->t0));
    }

    #[Test]
    public function repeatOfTheAnnouncedStatusIsSuppressed(): void
    {
        self::assertTrue($this->dedup->shouldPublish(self::PEER, 1, 'suspected', $this->t0));

        // The gossip merge echo: identical news, over and over.
        self::assertFalse($this->dedup->shouldPublish(self::PEER, 1, 'suspected', $this->t0->modify('+2 seconds')));
        self::assertFalse($this->dedup->shouldPublish(self::PEER, 1, 'suspected', $this->t0->modify('+90 seconds')));
    }

    #[Test]
    public function statusChangeAlwaysPublishesRecoveryIsNeverLost(): void
    {
        self::assertTrue($this->dedup->shouldPublish(self::PEER, 1, 'up', $this->t0));
        self::assertTrue($this->dedup->shouldPublish(self::PEER, 1, 'suspected', $this->t0->modify('+1 seconds')));

        // Immediate recovery — a net state change the subscriber must see.
        self::assertTrue($this->dedup->shouldPublish(self::PEER, 1, 'up', $this->t0->modify('+2 seconds')));

        // And the repeat of the recovered state is again suppressed.
        self::assertFalse($this->dedup->shouldPublish(self::PEER, 1, 'up', $this->t0->modify('+3 seconds')));
    }

    #[Test]
    public function olderIncarnationEchoIsAlwaysSuppressed(): void
    {
        self::assertTrue($this->dedup->shouldPublish(self::PEER, 2, 'up', $this->t0));

        // Stale gossip still circulating pre-refutation suspicion.
        self::assertFalse($this->dedup->shouldPublish(self::PEER, 1, 'suspected', $this->t0->modify('+1 seconds')));
    }

    #[Test]
    public function higherIncarnationPublishesImmediatelyAndResetsTheSlate(): void
    {
        self::assertTrue($this->dedup->shouldPublish(self::PEER, 1, 'suspected', $this->t0));

        // Refutation: the peer bumped its incarnation and re-asserts Up — never delayed.
        self::assertTrue($this->dedup->shouldPublish(self::PEER, 2, 'up', $this->t0->modify('+1 seconds')));
        self::assertTrue($this->dedup->shouldPublish(self::PEER, 2, 'suspected', $this->t0->modify('+2 seconds')));
    }

    #[Test]
    public function postDownReadmissionChurnIsSuppressedDuringTheQuietPeriod(): void
    {
        self::assertTrue($this->dedup->shouldPublish(self::PEER, 1, 'up', $this->t0));
        self::assertTrue($this->dedup->shouldPublish(self::PEER, 1, 'down', $this->t0->modify('+10 seconds')));

        // Stale gossip re-adds the departed member and it cycles Suspect/Down again.
        self::assertFalse($this->dedup->shouldPublish(self::PEER, 1, 'suspected', $this->t0->modify('+12 seconds')));
        self::assertFalse($this->dedup->shouldPublish(self::PEER, 1, 'down', $this->t0->modify('+22 seconds')));
        self::assertFalse($this->dedup->shouldPublish(self::PEER, 1, 'up', $this->t0->modify('+25 seconds')));
    }

    #[Test]
    public function sameIncarnationRejoinPublishesAfterTheQuietPeriod(): void
    {
        self::assertTrue($this->dedup->shouldPublish(self::PEER, 1, 'down', $this->t0));

        // C1 rejoin = process restart at incarnation 1 again, typically well after the
        // quiet period — the fresh NodeUp must reach subscribers.
        self::assertTrue($this->dedup->shouldPublish(self::PEER, 1, 'up', $this->t0->modify('+45 seconds')));
    }

    #[Test]
    public function higherIncarnationBypassesTheDownQuietPeriod(): void
    {
        self::assertTrue($this->dedup->shouldPublish(self::PEER, 1, 'down', $this->t0));
        self::assertTrue($this->dedup->shouldPublish(self::PEER, 2, 'up', $this->t0->modify('+2 seconds')));
    }

    #[Test]
    public function peersAreTrackedIndependently(): void
    {
        self::assertTrue($this->dedup->shouldPublish(self::PEER, 1, 'suspected', $this->t0));
        self::assertTrue($this->dedup->shouldPublish('/cluster/prod/eu/app/node-3', 1, 'suspected', $this->t0));
    }

    #[Test]
    public function lastKnownIncarnationTracksTheSlate(): void
    {
        self::assertSame(1, $this->dedup->lastKnownIncarnation(self::PEER), 'unknown peers default to 1');

        $this->dedup->shouldPublish(self::PEER, 3, 'up', $this->t0);

        self::assertSame(3, $this->dedup->lastKnownIncarnation(self::PEER));
    }

    #[Test]
    public function customDownQuietPeriodIsHonoured(): void
    {
        $dedup = new MembershipEventDeduplicator(Duration::seconds(5));

        self::assertTrue($dedup->shouldPublish(self::PEER, 1, 'down', $this->t0));
        self::assertFalse($dedup->shouldPublish(self::PEER, 1, 'up', $this->t0->modify('+4 seconds')));
        self::assertTrue($dedup->shouldPublish(self::PEER, 1, 'up', $this->t0->modify('+6 seconds')));
    }

    #[Test]
    public function defaultQuietPeriodEdgeIsExclusiveAtThirtySeconds(): void
    {
        // Default period is 30 s. Readmission strictly inside the window is suppressed;
        // exactly at the 30 s edge it publishes again (the boundary is exclusive).
        self::assertTrue($this->dedup->shouldPublish(self::PEER, 1, 'down', $this->t0));
        self::assertFalse($this->dedup->shouldPublish(self::PEER, 1, 'up', $this->t0->modify('+29 seconds')));
        self::assertTrue($this->dedup->shouldPublish(self::PEER, 1, 'up', $this->t0->modify('+30 seconds')));
    }

    #[Test]
    public function slateMapEvictsTheOldestPeerBeyondTheCap(): void
    {
        $evictee = '/cluster/prod/eu/app/evictee';
        $this->dedup->shouldPublish($evictee, 5, 'up', $this->t0);

        // Fill the map to its 10 000-peer cap with fresh peers; the oldest (the evictee)
        // is dropped, but peers still within the window survive.
        for ($i = 1; $i <= 10_000; $i++) {
            $this->dedup->shouldPublish("/cluster/prod/eu/app/bulk-{$i}", 7, 'up', $this->t0);
        }

        self::assertSame(1, $this->dedup->lastKnownIncarnation($evictee), 'the oldest peer is evicted (defaults to 1)');
        self::assertSame(
            7,
            $this->dedup->lastKnownIncarnation('/cluster/prod/eu/app/bulk-5000'),
            'a peer still within the cap is retained',
        );
    }

    protected function setUp(): void
    {
        $this->dedup = new MembershipEventDeduplicator();
        $this->t0 = new DateTimeImmutable('2026-07-09T12:00:00Z');
    }
}
