<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Tests\Unit\Membership;

use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\Tcp\Membership\DepartedPeerTracker;
use Monadial\Nexus\Cluster\Tcp\Membership\MembershipEvent;
use Monadial\Nexus\Cluster\Tcp\Membership\MembershipEventPublisher;
use Monadial\Nexus\Cluster\Tcp\Membership\NodeDown;
use Monadial\Nexus\Cluster\Tcp\Membership\NodeUp;
use Monadial\Nexus\Cluster\Tcp\NodeEndpoint;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DepartedPeerTracker::class)]
final class DepartedPeerTrackerTest extends TestCase
{
    private NodeAddress $peer;

    private NodeEndpoint $endpoint;

    #[Test]
    public function unknownPeerIsAlive(): void
    {
        $tracker = new DepartedPeerTracker($this->recordingPublisher());

        self::assertTrue($tracker->isAlive($this->peer));
    }

    #[Test]
    public function nodeDownMarksPeerNotAlive(): void
    {
        $tracker = new DepartedPeerTracker($this->recordingPublisher());

        $tracker->publish(new NodeDown($this->peer));

        self::assertFalse($tracker->isAlive($this->peer));
    }

    #[Test]
    public function nodeUpAfterDownMarksPeerAliveAgain(): void
    {
        $tracker = new DepartedPeerTracker($this->recordingPublisher());

        $tracker->publish(new NodeDown($this->peer));
        $tracker->publish(new NodeUp($this->peer, $this->endpoint));

        self::assertTrue($tracker->isAlive($this->peer));
    }

    #[Test]
    public function forwardsEveryEventToTheInnerPublisher(): void
    {
        $inner = $this->recordingPublisher();
        $tracker = new DepartedPeerTracker($inner);

        $down = new NodeDown($this->peer);
        $up = new NodeUp($this->peer, $this->endpoint);
        $tracker->publish($down);
        $tracker->publish($up);

        self::assertSame([$down, $up], $inner->published);
    }

    protected function setUp(): void
    {
        $this->peer = new NodeAddress('production', 'eu', 'payments', 'node-2');
        $this->endpoint = NodeEndpoint::fromString('10.0.0.2:7355');
    }

    private function recordingPublisher(): MembershipEventPublisher
    {
        return new class implements MembershipEventPublisher {
            /** @var list<MembershipEvent> */
            public array $published = [];

            #[Override]
            public function publish(MembershipEvent $event): void
            {
                $this->published[] = $event;
            }
        };
    }
}
