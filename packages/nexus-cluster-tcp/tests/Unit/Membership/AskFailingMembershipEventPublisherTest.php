<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Tests\Unit\Membership;

use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\Tcp\Exception\PeerUnreachableException;
use Monadial\Nexus\Cluster\Tcp\Membership\AskFailingMembershipEventPublisher;
use Monadial\Nexus\Cluster\Tcp\Membership\MembershipEvent;
use Monadial\Nexus\Cluster\Tcp\Membership\MembershipEventPublisher;
use Monadial\Nexus\Cluster\Tcp\Membership\NodeDown;
use Monadial\Nexus\Cluster\Tcp\Messaging\TcpAskRegistry;
use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Monadial\Nexus\Runtime\Duration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AskFailingMembershipEventPublisher::class)]
final class AskFailingMembershipEventPublisherTest extends TestCase
{
    /** @var list<MembershipEvent> */
    private array $recorded = [];

    private TcpAskRegistry $registry;

    private AskFailingMembershipEventPublisher $publisher;

    #[Test]
    public function failsInFlightAsksToTheDownedNodeThenForwardsTheEvent(): void
    {
        $node = new NodeAddress('mesh', 'dc', 'app', 'node-2');
        $future = $this->registry->register('c1', Duration::seconds(30), ActorPath::fromString('/user/x'), $node);

        $this->publisher->publish(new NodeDown($node));

        self::assertSame(0, $this->registry->count(), 'the ask to the downed node was failed');

        try {
            $future->await();
            self::fail('expected PeerUnreachableException');
        } catch (PeerUnreachableException) {
            // Expected.
        }

        self::assertCount(1, $this->recorded, 'the event is still forwarded to the inner publisher');
    }

    #[Test]
    public function leavesAsksToOtherNodesUntouched(): void
    {
        $target = new NodeAddress('mesh', 'dc', 'app', 'node-2');
        $this->registry->register('c1', Duration::seconds(30), ActorPath::fromString('/user/x'), $target);

        $this->publisher->publish(new NodeDown(new NodeAddress('mesh', 'dc', 'app', 'node-3')));

        self::assertSame(1, $this->registry->count(), 'an ask to a different node is not failed');
        self::assertCount(1, $this->recorded);
    }

    protected function setUp(): void
    {
        $this->registry = new TcpAskRegistry(new TestRuntime());

        $inner = new class ($this->recorded) implements MembershipEventPublisher {
            /**
             * @param list<MembershipEvent> $recorded
             */
            public function __construct(private array &$recorded) {}

            public function publish(MembershipEvent $event): void
            {
                $this->recorded[] = $event;
            }
        };

        $this->publisher = new AskFailingMembershipEventPublisher($inner, $this->registry);
    }
}
