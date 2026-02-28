<?php

declare(strict_types=1);

namespace Monadial\Nexus\WorkerPool\Tests\Unit;

use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\LocalActorRef;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Exception\AskTimeoutException;
use Monadial\Nexus\Core\Mailbox\Envelope;
use Monadial\Nexus\Core\Tests\Support\TestClock;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\WorkerPool\ConsistentHashRing;
use Monadial\Nexus\WorkerPool\Directory\InMemoryWorkerDirectory;
use Monadial\Nexus\WorkerPool\Protocol\WorkerAskAck;
use Monadial\Nexus\WorkerPool\Protocol\WorkerAskCancel;
use Monadial\Nexus\WorkerPool\Protocol\WorkerAskCancelled;
use Monadial\Nexus\WorkerPool\Protocol\WorkerAskReply;
use Monadial\Nexus\WorkerPool\Protocol\WorkerAskRequest;
use Monadial\Nexus\WorkerPool\Tests\Unit\Support\Ping;
use Monadial\Nexus\WorkerPool\Transport\InMemoryWorkerTransport;
use Monadial\Nexus\WorkerPool\WorkerActorRef;
use Monadial\Nexus\WorkerPool\WorkerNode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(WorkerNode::class)]
final class WorkerNodeTest extends TestCase
{
    private TestRuntime $runtime;
    private InMemoryWorkerTransport $transport;
    private InMemoryWorkerDirectory $directory;

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
    public function spawnReturnsWorkerActorRefWhenHashDoesNotMatch(): void
    {
        $ring = new ConsistentHashRing(4);
        $remoteName = $this->findNameNotForWorker($ring, 0);

        $node = $this->createNode(workerId: 0, workerCount: 4);
        $props = Props::fromBehavior(Behavior::receive(
            static fn($ctx, $msg) => Behavior::same(),
        ));

        $ref = $node->spawn($props, $remoteName);

        self::assertInstanceOf(WorkerActorRef::class, $ref);
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

        $envelope = Envelope::of(
            new Ping('from-remote'),
            ActorPath::fromString('/user/remote-sender'),
            ActorPath::fromString("/user/{$localName}"),
        );
        $this->transport->receive($envelope);

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
    public function actorForReturnsWorkerRefForRemoteActor(): void
    {
        $this->directory->register('/user/remote-actor', 5);

        $node = $this->createNode(workerId: 0, workerCount: 8);

        $ref = $node->actorFor('/user/remote-actor');

        self::assertNotNull($ref);
        self::assertInstanceOf(WorkerActorRef::class, $ref);
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
        self::assertInstanceOf(WorkerActorRef::class, $remoteRef);

        $node->start();

        $future = $remoteRef->ask(new Ping('request'), Duration::seconds(5));
        $sent = $this->transport->getSent();
        self::assertNotEmpty($sent);

        $requestEnvelope = $sent[0]['envelope'];
        self::assertInstanceOf(WorkerAskRequest::class, $requestEnvelope->message);
        $request = $requestEnvelope->message;
        self::assertSame("/user/{$remoteName}", (string) $request->targetPath);
        self::assertStringStartsWith('/worker/0/temp/ask-', (string) $request->replyToPath);
        self::assertInstanceOf(Ping::class, $request->payload);

        $replyEnvelope = Envelope::of(
            new WorkerAskReply($request->requestId, new Ping('response')),
            ActorPath::root(),
            ActorPath::root(),
        )->withRequestId($request->requestId);

        $this->transport->receive($replyEnvelope);

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
        self::assertInstanceOf(WorkerActorRef::class, $remoteRef);
        $node->start();

        $future = $remoteRef->ask(new Ping('request'), Duration::seconds(5));
        $requestEnvelope = $this->transport->getSent()[0]['envelope'];
        self::assertInstanceOf(WorkerAskRequest::class, $requestEnvelope->message);
        $requestId = $requestEnvelope->message->requestId;

        $firstReply = Envelope::of(
            new WorkerAskReply($requestId, new Ping('first')),
            ActorPath::root(),
            ActorPath::root(),
        )->withRequestId($requestId);
        $secondReply = Envelope::of(
            new WorkerAskReply($requestId, new Ping('second')),
            ActorPath::root(),
            ActorPath::root(),
        )->withRequestId($requestId);

        $this->transport->receive($firstReply);
        $this->transport->receive($secondReply);

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
        self::assertInstanceOf(WorkerActorRef::class, $remoteRef);

        $node->start();

        $future = $remoteRef->ask(new Ping('request'), Duration::seconds(5));
        $requestEnvelope = $this->transport->getSent()[0]['envelope'];
        self::assertInstanceOf(WorkerAskRequest::class, $requestEnvelope->message);
        $request = $requestEnvelope->message;
        $future->cancel();

        $sent = $this->transport->getSent();
        self::assertGreaterThanOrEqual(2, count($sent));

        $cancelEnvelope = $sent[array_key_last($sent)]['envelope'];
        self::assertInstanceOf(WorkerAskCancel::class, $cancelEnvelope->message);
        self::assertSame($request->requestId, $cancelEnvelope->requestId);
        self::assertSame($request->correlationId, $cancelEnvelope->correlationId);
        self::assertSame($request->causationId, $cancelEnvelope->causationId);
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
        self::assertInstanceOf(WorkerActorRef::class, $remoteRef);
        $node->start();

        $future = $remoteRef->ask(new Ping('request'), Duration::seconds(5));
        self::assertFalse($future->isResolved());

        self::assertCount(1, $this->transport->getSent());

        $this->runtime->advanceTime(Duration::millis(55));
        self::assertCount(2, $this->transport->getSent());

        $requestEnvelope = $this->transport->getSent()[0]['envelope'];
        self::assertInstanceOf(WorkerAskRequest::class, $requestEnvelope->message);
        $requestId = $requestEnvelope->message->requestId;

        $ackEnvelope = Envelope::of(
            new WorkerAskAck($requestId),
            ActorPath::root(),
            ActorPath::root(),
        )->withRequestId($requestId);

        $this->transport->receive($ackEnvelope);

        $afterAck = count($this->transport->getSent());
        $this->runtime->advanceTime(Duration::millis(200));
        self::assertCount($afterAck, $this->transport->getSent());
    }

    #[Test]
    public function remoteAskTimeoutRejectsFutureAndSendsBestEffortCancel(): void
    {
        $ring = new ConsistentHashRing(4);
        $remoteName = $this->findNameNotForWorker($ring, 0);

        $node = $this->createNode(workerId: 0, workerCount: 4);
        $props = Props::fromBehavior(Behavior::receive(
            static fn($ctx, $msg) => Behavior::same(),
        ));
        $remoteRef = $node->spawn($props, $remoteName);
        self::assertInstanceOf(WorkerActorRef::class, $remoteRef);
        $node->start();

        $future = $remoteRef->ask(new Ping('request'), Duration::millis(25));
        $this->runtime->advanceTime(Duration::millis(30));

        self::expectException(AskTimeoutException::class);

        try {
            $future->await();
        } finally {
            $sent = $this->transport->getSent();
            self::assertNotEmpty($sent);
            $lastEnvelope = $sent[array_key_last($sent)]['envelope'];
            self::assertInstanceOf(WorkerAskCancel::class, $lastEnvelope->message);
        }
    }

    #[Test]
    public function duplicateUnknownWorkerAskRequestReturnsCancelledBothTimes(): void
    {
        $node = $this->createNode(workerId: 0, workerCount: 4);
        $node->start();

        $request = new WorkerAskRequest(
            requestId: 'req-duplicate',
            correlationId: 'corr-duplicate',
            causationId: 'cause-duplicate',
            targetPath: ActorPath::fromString('/user/missing'),
            replyToWorker: 9,
            replyToPath: ActorPath::fromString('/temp/worker'),
            payload: new Ping('hello'),
        );

        $envelope = Envelope::of($request, ActorPath::root(), ActorPath::root())
            ->withRequestId($request->requestId)
            ->withCorrelationId($request->correlationId)
            ->withCausationId($request->causationId);

        $this->transport->receive($envelope);
        $this->transport->receive($envelope);

        $sent = $this->transport->getSent();
        self::assertCount(2, $sent);

        $first = $sent[0]['envelope'];
        $second = $sent[1]['envelope'];

        self::assertInstanceOf(WorkerAskCancelled::class, $first->message);
        self::assertInstanceOf(WorkerAskCancelled::class, $second->message);
        self::assertSame($request->requestId, $first->requestId);
        self::assertSame($request->correlationId, $first->correlationId);
        self::assertSame($request->causationId, $first->causationId);
        self::assertSame($request->requestId, $second->requestId);
        self::assertSame($request->correlationId, $second->correlationId);
        self::assertSame($request->causationId, $second->causationId);
    }

    protected function setUp(): void
    {
        $this->runtime = new TestRuntime(new TestClock());
        $this->transport = new InMemoryWorkerTransport();
        $this->directory = new InMemoryWorkerDirectory();
    }

    private function createNode(int $workerId, int $workerCount): WorkerNode
    {
        $system = ActorSystem::create("worker-{$workerId}", $this->runtime);

        return new WorkerNode(
            $workerId,
            $system,
            $this->transport,
            new ConsistentHashRing($workerCount),
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
