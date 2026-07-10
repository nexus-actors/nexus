<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Tests\Unit\Membership;

use DateTimeImmutable;
use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\Tcp\Membership\ClusterView;
use Monadial\Nexus\Cluster\Tcp\Membership\MemberRecord;
use Monadial\Nexus\Cluster\Tcp\Membership\MemberStatus;
use Monadial\Nexus\Cluster\Tcp\NodeEndpoint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ClusterView::class)]
#[CoversClass(MemberRecord::class)]
#[CoversClass(MemberStatus::class)]
final class ClusterViewTest extends TestCase
{
    private DateTimeImmutable $t0;

    #[Test]
    public function emptyViewHasNoMembers(): void
    {
        self::assertSame([], ClusterView::empty()->nodes());
    }

    #[Test]
    public function withMemberAddsAndHasReports(): void
    {
        $address = $this->address('node-2');
        $view = ClusterView::empty()->withMember($this->record($address, 1, MemberStatus::Up, $this->t0));

        self::assertTrue($view->has($address));
        self::assertFalse($view->has($this->address('node-9')));
        self::assertCount(1, $view->nodes());
    }

    #[Test]
    public function withStatusUpdatesExistingMember(): void
    {
        $address = $this->address('node-2');
        $later = $this->t0->modify('+5 seconds');
        $view = ClusterView::empty()
            ->withMember($this->record($address, 1, MemberStatus::Up, $this->t0))
            ->withStatus($address, MemberStatus::Suspect, $later);

        self::assertSame(MemberStatus::Suspect, $view->members[$address->toPathPrefix()]->status);
        self::assertEquals($later, $view->members[$address->toPathPrefix()]->lastSeen);
    }

    #[Test]
    public function withStatusIsNoOpForUnknownMember(): void
    {
        $view = ClusterView::empty();

        self::assertSame($view, $view->withStatus($this->address('ghost'), MemberStatus::Down, $this->t0));
    }

    #[Test]
    public function withoutNodeRemovesMember(): void
    {
        $address = $this->address('node-2');
        $view = ClusterView::empty()
            ->withMember($this->record($address, 1, MemberStatus::Up, $this->t0))
            ->withoutNode($address);

        self::assertFalse($view->has($address));
    }

    #[Test]
    public function upNodesFiltersByStatus(): void
    {
        $view = ClusterView::empty()
            ->withMember($this->record($this->address('node-2'), 1, MemberStatus::Up, $this->t0))
            ->withMember($this->record($this->address('node-3'), 1, MemberStatus::Suspect, $this->t0))
            ->withMember($this->record($this->address('node-4'), 1, MemberStatus::Up, $this->t0));

        self::assertCount(2, $view->upNodes());
        self::assertCount(3, $view->nodes());
    }

    #[Test]
    public function mergeHigherIncarnationWins(): void
    {
        $address = $this->address('node-2');
        $local = ClusterView::empty()->withMember($this->record($address, 1, MemberStatus::Up, $this->t0));
        $other = ClusterView::empty()->withMember($this->record($address, 2, MemberStatus::Down, $this->t0));

        $merged = $local->merge($other);

        self::assertSame(2, $merged->members[$address->toPathPrefix()]->incarnation);
        self::assertSame(MemberStatus::Down, $merged->members[$address->toPathPrefix()]->status);
    }

    #[Test]
    public function mergeKeepsLocalWhenIncarnationHigher(): void
    {
        $address = $this->address('node-2');
        $local = ClusterView::empty()->withMember($this->record($address, 2, MemberStatus::Up, $this->t0));
        $other = ClusterView::empty()->withMember($this->record($address, 1, MemberStatus::Down, $this->t0));

        $merged = $local->merge($other);

        self::assertSame(2, $merged->members[$address->toPathPrefix()]->incarnation);
        self::assertSame(MemberStatus::Up, $merged->members[$address->toPathPrefix()]->status);
    }

    #[Test]
    public function mergeEqualIncarnationWorseStatusWins(): void
    {
        $address = $this->address('node-2');
        $local = ClusterView::empty()->withMember($this->record($address, 1, MemberStatus::Up, $this->t0));
        $other = ClusterView::empty()->withMember($this->record($address, 1, MemberStatus::Suspect, $this->t0));

        self::assertSame(MemberStatus::Suspect, $local->merge($other)->members[$address->toPathPrefix()]->status);
        self::assertSame(MemberStatus::Suspect, $other->merge($local)->members[$address->toPathPrefix()]->status);
    }

    #[Test]
    public function mergeAddsUnknownMembers(): void
    {
        $local = ClusterView::empty()->withMember(
            $this->record($this->address('node-2'), 1, MemberStatus::Up, $this->t0),
        );
        $other = ClusterView::empty()->withMember(
            $this->record($this->address('node-3'), 1, MemberStatus::Up, $this->t0),
        );

        $merged = $local->merge($other);

        self::assertTrue($merged->has($this->address('node-2')));
        self::assertTrue($merged->has($this->address('node-3')));
    }

    #[Test]
    public function mergeEqualIncarnationEqualStatusPrefersLaterLastSeen(): void
    {
        $address = $this->address('node-2');
        $later = $this->t0->modify('+10 seconds');
        $local = ClusterView::empty()->withMember($this->record($address, 1, MemberStatus::Up, $this->t0));
        $other = ClusterView::empty()->withMember($this->record($address, 1, MemberStatus::Up, $later));

        self::assertEquals($later, $local->merge($other)->members[$address->toPathPrefix()]->lastSeen);
    }

    #[Test]
    public function mergeExactTieKeepsLocalRecord(): void
    {
        // Equal incarnation, equal status rank, equal lastSeen — but differing endpoints. The local
        // record must win so a value-identical merge is a no-op (no needless record churn).
        $address = $this->address('node-2');
        $local = ClusterView::empty()->withMember(new MemberRecord(
            $address,
            NodeEndpoint::fromString('10.0.0.2:7355'),
            1,
            MemberStatus::Up,
            $this->t0,
        ));
        $incoming = ClusterView::empty()->withMember(new MemberRecord(
            $address,
            NodeEndpoint::fromString('10.9.9.9:7355'),
            1,
            MemberStatus::Up,
            $this->t0,
        ));

        $merged = $local->merge($incoming);

        self::assertSame('10.0.0.2:7355', (string) $merged->members[$address->toPathPrefix()]->endpoint);
    }

    protected function setUp(): void
    {
        $this->t0 = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
    }

    private function address(string $node): NodeAddress
    {
        return new NodeAddress('prod', 'eu', 'payments', $node);
    }

    private function record(
        NodeAddress $address,
        int $incarnation,
        MemberStatus $status,
        DateTimeImmutable $lastSeen,
    ): MemberRecord {
        return new MemberRecord($address, NodeEndpoint::fromString('10.0.0.2:7355'), $incarnation, $status, $lastSeen);
    }
}
