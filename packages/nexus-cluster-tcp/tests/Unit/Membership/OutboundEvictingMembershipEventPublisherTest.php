<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Tests\Unit\Membership;

use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\Tcp\Membership\MembershipEvent;
use Monadial\Nexus\Cluster\Tcp\Membership\NodeDown;
use Monadial\Nexus\Cluster\Tcp\Membership\NodeSuspected;
use Monadial\Nexus\Cluster\Tcp\Membership\NodeUp;
use Monadial\Nexus\Cluster\Tcp\Membership\OutboundEvictingMembershipEventPublisher;
use Monadial\Nexus\Cluster\Tcp\Membership\SuspicionReason;
use Monadial\Nexus\Cluster\Tcp\NodeEndpoint;
use Monadial\Nexus\Cluster\Tcp\Tests\Support\RecordingEventPublisher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OutboundEvictingMembershipEventPublisher::class)]
final class OutboundEvictingMembershipEventPublisherTest extends TestCase
{
    private NodeAddress $node;

    #[Test]
    public function evictsTheOutboundConnectionOnNodeDownThenForwardsTheEvent(): void
    {
        $inner = new RecordingEventPublisher();

        /** @var list<NodeAddress> $evicted */
        $evicted = [];
        $publisher = new OutboundEvictingMembershipEventPublisher(
            $inner,
            static function (NodeAddress $n) use (&$evicted): void {
                $evicted[] = $n;
            },
        );

        $event = new NodeDown($this->node);
        $publisher->publish($event);

        self::assertSame([$this->node], $evicted, 'NodeDown must trigger eviction of the outbound connection');
        self::assertSame([$event], $inner->events(), 'the event must still be forwarded to the inner publisher');
    }

    #[Test]
    public function doesNotEvictForNonDownEvents(): void
    {
        $inner = new RecordingEventPublisher();

        /** @var list<NodeAddress> $evicted */
        $evicted = [];
        $publisher = new OutboundEvictingMembershipEventPublisher(
            $inner,
            static function (NodeAddress $n) use (&$evicted): void {
                $evicted[] = $n;
            },
        );

        $endpoint = NodeEndpoint::fromString('10.0.0.2:7355');
        $events = [
            new NodeUp($this->node, $endpoint),
            new NodeSuspected($this->node, SuspicionReason::Phi),
        ];

        foreach ($events as $event) {
            $publisher->publish($event);
        }

        self::assertSame([], $evicted, 'only NodeDown may evict — a flapping/suspected peer must stay dialable');
        self::assertSame($events, $inner->events());
    }

    #[Test]
    public function forwardsEveryEventRegardlessOfType(): void
    {
        $inner = new RecordingEventPublisher();
        $publisher = new OutboundEvictingMembershipEventPublisher($inner, static fn(NodeAddress $n): null => null);

        /** @var list<MembershipEvent> $events */
        $events = [
            new NodeUp($this->node, NodeEndpoint::fromString('10.0.0.2:7355')),
            new NodeDown($this->node),
        ];

        foreach ($events as $event) {
            $publisher->publish($event);
        }

        self::assertSame($events, $inner->events());
    }

    protected function setUp(): void
    {
        $this->node = new NodeAddress('production', 'eu', 'payments', 'node-2');
    }
}
