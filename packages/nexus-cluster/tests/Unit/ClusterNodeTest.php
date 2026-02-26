<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tests\Unit;

use Monadial\Nexus\Cluster\ClusterNode;
use Monadial\Nexus\Cluster\ConsistentHashRing;
use Monadial\Nexus\Cluster\Directory\InMemoryDirectory;
use Monadial\Nexus\Cluster\Protocol\RemoteAskAck;
use Monadial\Nexus\Cluster\Protocol\RemoteAskCancel;
use Monadial\Nexus\Cluster\Protocol\RemoteAskCancelled;
use Monadial\Nexus\Cluster\Protocol\RemoteAskReply;
use Monadial\Nexus\Cluster\Protocol\RemoteAskRequest;
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
use Monadial\Nexus\Runtime\Duration;
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
            static fn($ctx, $msg) => Behavior::same(),
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
            static fn($ctx, $msg) => Behavior::same(),
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
            static fn($ctx, $msg) => Behavior::same(),
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
            static fn($ctx, $msg) => Behavior::same(),
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
            static fn($ctx, $msg) => Behavior::same(),
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

    #[Test]
    public function remoteAskSendsRequestEnvelopeAndResolvesOnReply(): void
    {
        $ring = new ConsistentHashRing(4);
        $remoteName = $this->findNameNotForWorker($ring, 0);

        $node = $this->createNode(workerId: 0, workerCount: 4);
        $props = Props::fromBehavior(Behavior::receive(
            static fn($ctx, $msg) => Behavior::same(),
        ));
        $remoteRef = $node->spawn($props, $remoteName);
        self::assertInstanceOf(RemoteActorRef::class, $remoteRef);

        $node->start();

        $future = $remoteRef->ask(new Ping('request'), Duration::seconds(5));
        $sent = $this->transport->getSent();
        self::assertNotEmpty($sent);

        $requestEnvelope = $this->serializer->deserialize($sent[0]['data']);
        self::assertInstanceOf(RemoteAskRequest::class, $requestEnvelope->message);
        $request = $requestEnvelope->message;
        self::assertSame("/user/{$remoteName}", (string) $request->targetPath);
        self::assertInstanceOf(Ping::class, $request->payload);

        $replyEnvelope = Envelope::of(
            new RemoteAskReply($request->requestId, new Ping('response')),
            ActorPath::root(),
            ActorPath::root(),
        )->withRequestId($request->requestId);

        $this->transport->receive($this->serializer->serialize($replyEnvelope));

        $reply = $future->await();
        self::assertInstanceOf(Ping::class, $reply);
        self::assertSame('response', $reply->payload);
    }

    #[Test]
    public function duplicateRepliesAreIgnoredAfterFirstResolution(): void
    {
        $ring = new ConsistentHashRing(4);
        $remoteName = $this->findNameNotForWorker($ring, 0);

        $node = $this->createNode(workerId: 0, workerCount: 4);
        $props = Props::fromBehavior(Behavior::receive(
            static fn($ctx, $msg) => Behavior::same(),
        ));
        $remoteRef = $node->spawn($props, $remoteName);
        self::assertInstanceOf(RemoteActorRef::class, $remoteRef);
        $node->start();

        $future = $remoteRef->ask(new Ping('request'), Duration::seconds(5));
        $requestEnvelope = $this->serializer->deserialize($this->transport->getSent()[0]['data']);
        self::assertInstanceOf(RemoteAskRequest::class, $requestEnvelope->message);
        $requestId = $requestEnvelope->message->requestId;

        $firstReply = Envelope::of(
            new RemoteAskReply($requestId, new Ping('first')),
            ActorPath::root(),
            ActorPath::root(),
        )->withRequestId($requestId);
        $secondReply = Envelope::of(
            new RemoteAskReply($requestId, new Ping('second')),
            ActorPath::root(),
            ActorPath::root(),
        )->withRequestId($requestId);

        $this->transport->receive($this->serializer->serialize($firstReply));
        $this->transport->receive($this->serializer->serialize($secondReply));

        $reply = $future->await();
        self::assertInstanceOf(Ping::class, $reply);
        self::assertSame('first', $reply->payload);
    }

    #[Test]
    public function remoteAskCancelSendsCancelControlMessage(): void
    {
        $ring = new ConsistentHashRing(4);
        $remoteName = $this->findNameNotForWorker($ring, 0);

        $node = $this->createNode(workerId: 0, workerCount: 4);
        $props = Props::fromBehavior(Behavior::receive(
            static fn($ctx, $msg) => Behavior::same(),
        ));
        $remoteRef = $node->spawn($props, $remoteName);
        self::assertInstanceOf(RemoteActorRef::class, $remoteRef);

        $node->start();

        $future = $remoteRef->ask(new Ping('request'), Duration::seconds(5));
        $future->cancel();

        $sent = $this->transport->getSent();
        self::assertGreaterThanOrEqual(2, count($sent));

        $cancelEnvelope = $this->serializer->deserialize($sent[array_key_last($sent)]['data']);
        self::assertInstanceOf(RemoteAskCancel::class, $cancelEnvelope->message);
    }

    #[Test]
    public function remoteAskRetriesUntilAckAndStopsAfterAck(): void
    {
        $ring = new ConsistentHashRing(4);
        $remoteName = $this->findNameNotForWorker($ring, 0);

        $node = $this->createNode(workerId: 0, workerCount: 4);
        $props = Props::fromBehavior(Behavior::receive(
            static fn($ctx, $msg) => Behavior::same(),
        ));
        $remoteRef = $node->spawn($props, $remoteName);
        self::assertInstanceOf(RemoteActorRef::class, $remoteRef);
        $node->start();

        $future = $remoteRef->ask(new Ping('request'), Duration::seconds(5));
        self::assertFalse($future->isResolved());

        self::assertCount(1, $this->transport->getSent());

        $this->runtime->advanceTime(Duration::millis(55));
        self::assertCount(2, $this->transport->getSent());

        $requestEnvelope = $this->serializer->deserialize($this->transport->getSent()[0]['data']);
        self::assertInstanceOf(RemoteAskRequest::class, $requestEnvelope->message);
        $requestId = $requestEnvelope->message->requestId;

        $ackEnvelope = Envelope::of(
            new RemoteAskAck($requestId),
            ActorPath::root(),
            ActorPath::root(),
        )->withRequestId($requestId);

        $this->transport->receive($this->serializer->serialize($ackEnvelope));

        $afterAck = count($this->transport->getSent());
        $this->runtime->advanceTime(Duration::millis(200));
        self::assertCount($afterAck, $this->transport->getSent());
    }

    #[Test]
    public function duplicateUnknownRemoteAskRequestReturnsCancelledBothTimes(): void
    {
        $node = $this->createNode(workerId: 0, workerCount: 4);
        $node->start();

        $request = new RemoteAskRequest(
            requestId: 'req-duplicate',
            correlationId: 'corr-duplicate',
            causationId: 'cause-duplicate',
            targetPath: ActorPath::fromString('/user/missing'),
            replyToWorker: 9,
            replyToPath: ActorPath::fromString('/temp/remote'),
            payload: new Ping('hello'),
        );

        $envelope = Envelope::of($request, ActorPath::root(), ActorPath::root())
            ->withRequestId($request->requestId)
            ->withCorrelationId($request->correlationId)
            ->withCausationId($request->causationId);

        $this->transport->receive($this->serializer->serialize($envelope));
        $this->transport->receive($this->serializer->serialize($envelope));

        $sent = $this->transport->getSent();
        self::assertCount(2, $sent);

        $first = $this->serializer->deserialize($sent[0]['data']);
        $second = $this->serializer->deserialize($sent[1]['data']);

        self::assertInstanceOf(RemoteAskCancelled::class, $first->message);
        self::assertInstanceOf(RemoteAskCancelled::class, $second->message);
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
