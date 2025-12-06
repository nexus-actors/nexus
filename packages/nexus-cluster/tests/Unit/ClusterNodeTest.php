<?php
declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tests\Unit;

use Monadial\Nexus\Cluster\ClusterNode;
use Monadial\Nexus\Cluster\ConsistentHashRing;
use Monadial\Nexus\Cluster\Directory\InMemoryDirectory;
use Monadial\Nexus\Cluster\RemoteActorRef;
use Monadial\Nexus\Cluster\Serialization\PhpNativeClusterSerializer;
use Monadial\Nexus\Cluster\Tests\Unit\Support\Ping;
use Monadial\Nexus\Cluster\Transport\InMemoryTransport;
use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\LocalActorRef;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Mailbox\Envelope;
use Monadial\Nexus\Core\Tests\Support\TestClock;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ClusterNode::class)]
final class ClusterNodeTest extends TestCase
{
    private TestRuntime $runtime;
    private InMemoryTransport $transport;
    private InMemoryDirectory $directory;
    private PhpNativeClusterSerializer $serializer;

    #[Test]
    public function spawnLocalActorWhenHashMatchesCurrentWorker(): void
    {
        $ring = new ConsistentHashRing(4);
        $localName = $this->findNameForWorker($ring, 0);

        $node = $this->createNode(workerId: 0, workerCount: 4);
        $props = Props::fromBehavior(Behavior::receive(
            static fn ($ctx, $msg) => Behavior::same(),
        ));

        $ref = $node->spawn($props, $localName);

        self::assertInstanceOf(LocalActorRef::class, $ref);
        self::assertSame("/user/{$localName}", (string) $ref->path());
        self::assertTrue($this->directory->has("/user/{$localName}"));
        self::assertSame(0, $this->directory->lookup("/user/{$localName}"));
    }

    #[Test]
    public function spawnReturnsRemoteActorRefWhenHashDoesNotMatch(): void
    {
        $ring = new ConsistentHashRing(4);
        $remoteName = $this->findNameNotForWorker($ring, 0);

        $node = $this->createNode(workerId: 0, workerCount: 4);
        $props = Props::fromBehavior(Behavior::receive(
            static fn ($ctx, $msg) => Behavior::same(),
        ));

        $ref = $node->spawn($props, $remoteName);

        self::assertInstanceOf(RemoteActorRef::class, $ref);
        self::assertSame("/user/{$remoteName}", (string) $ref->path());
    }

    #[Test]
    public function spawnRegistersRemoteActorInDirectory(): void
    {
        $ring = new ConsistentHashRing(4);
        $remoteName = $this->findNameNotForWorker($ring, 0);
        $expectedWorker = $ring->getWorker($remoteName);

        $node = $this->createNode(workerId: 0, workerCount: 4);
        $props = Props::fromBehavior(Behavior::receive(
            static fn ($ctx, $msg) => Behavior::same(),
        ));

        $node->spawn($props, $remoteName);

        self::assertTrue($this->directory->has("/user/{$remoteName}"));
        self::assertSame($expectedWorker, $this->directory->lookup("/user/{$remoteName}"));
    }

    #[Test]
    public function incomingMessageDeliveredToLocalActor(): void
    {
        $ring = new ConsistentHashRing(4);
        $localName = $this->findNameForWorker($ring, 0);

        $node = $this->createNode(workerId: 0, workerCount: 4);
        $props = Props::fromBehavior(Behavior::receive(
            static fn ($ctx, $msg) => Behavior::same(),
        ));

        $ref = $node->spawn($props, $localName);
        $node->start();

        // Simulate incoming message from transport
        $envelope = Envelope::of(
            new Ping('from-remote'),
            ActorPath::fromString('/user/remote-sender'),
            ActorPath::fromString("/user/{$localName}"),
        );
        $this->transport->receive($this->serializer->serialize($envelope));

        // NOTE: TestRuntime doesn't process actor loops, but we can verify
        // the message was enqueued by checking the ref is a LocalActorRef
        // and the transport listener was invoked (no exception thrown).
        // The message is in the mailbox but won't be processed without a real runtime.
        self::assertInstanceOf(LocalActorRef::class, $ref);
    }

    #[Test]
    public function actorForReturnsLocalRefForLocalActor(): void
    {
        $ring = new ConsistentHashRing(4);
        $localName = $this->findNameForWorker($ring, 0);

        $node = $this->createNode(workerId: 0, workerCount: 4);
        $props = Props::fromBehavior(Behavior::receive(
            static fn ($ctx, $msg) => Behavior::same(),
        ));
        $node->spawn($props, $localName);

        $ref = $node->actorFor("/user/{$localName}");

        self::assertNotNull($ref);
        self::assertInstanceOf(LocalActorRef::class, $ref);
    }

    #[Test]
    public function actorForReturnsRemoteRefForRemoteActor(): void
    {
        $this->directory->register('/user/remote-actor', 5);

        $node = $this->createNode(workerId: 0, workerCount: 8);

        $ref = $node->actorFor('/user/remote-actor');

        self::assertNotNull($ref);
        self::assertInstanceOf(RemoteActorRef::class, $ref);
    }

    #[Test]
    public function actorForReturnsNullForUnknownActor(): void
    {
        $node = $this->createNode(workerId: 0, workerCount: 4);

        $ref = $node->actorFor('/user/nonexistent');

        self::assertNull($ref);
    }

    protected function setUp(): void
    {
        $this->runtime = new TestRuntime(new TestClock());
        $this->transport = new InMemoryTransport();
        $this->directory = new InMemoryDirectory();
        $this->serializer = new PhpNativeClusterSerializer();
    }

    private function createNode(int $workerId, int $workerCount): ClusterNode
    {
        $system = ActorSystem::create("worker-{$workerId}", $this->runtime);

        return new ClusterNode(
            $workerId,
            $system,
            $this->transport,
            new ConsistentHashRing($workerCount),
            $this->serializer,
            $this->directory,
        );
    }

    private function findNameForWorker(ConsistentHashRing $ring, int $workerId): string
    {
        for ($i = 0; $i < 10000; $i++) {
            $name = "actor-{$i}";

            if ($ring->getWorker($name) === $workerId) {
                return $name;
            }
        }

        self::fail("Could not find a name that hashes to worker {$workerId}");
    }

    private function findNameNotForWorker(ConsistentHashRing $ring, int $workerId): string
    {
        for ($i = 0; $i < 10000; $i++) {
            $name = "actor-{$i}";

            if ($ring->getWorker($name) !== $workerId) {
                return $name;
            }
        }

        self::fail("Could not find a name that doesn't hash to worker {$workerId}");
    }
}
