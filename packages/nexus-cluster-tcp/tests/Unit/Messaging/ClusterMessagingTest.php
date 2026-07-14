<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Tests\Unit\Messaging;

use InvalidArgumentException;
use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\Tcp\Exception\AskCapacityExceededException;
use Monadial\Nexus\Cluster\Tcp\Exception\PeerUnreachableException;
use Monadial\Nexus\Cluster\Tcp\Messaging\ClusterMessageCodec;
use Monadial\Nexus\Cluster\Tcp\Messaging\ClusterRef;
use Monadial\Nexus\Cluster\Tcp\Messaging\ClusterRefFactory;
use Monadial\Nexus\Cluster\Tcp\Messaging\ClusterReplyRef;
use Monadial\Nexus\Cluster\Tcp\Messaging\DeliveryOutcome;
use Monadial\Nexus\Cluster\Tcp\Messaging\FrameIngress;
use Monadial\Nexus\Cluster\Tcp\Messaging\InboxRouter;
use Monadial\Nexus\Cluster\Tcp\Messaging\LocalActorRegistry;
use Monadial\Nexus\Cluster\Tcp\Messaging\LocalDelivery;
use Monadial\Nexus\Cluster\Tcp\Messaging\NoopTraceContextExtractor;
use Monadial\Nexus\Cluster\Tcp\Messaging\RecordingOutboundSink;
use Monadial\Nexus\Cluster\Tcp\Messaging\TcpAskRegistry;
use Monadial\Nexus\Cluster\Tcp\Payload\MessagePayload;
use Monadial\Nexus\Cluster\Tcp\Tests\Fixture\Ping;
use Monadial\Nexus\Cluster\Tcp\Tests\Fixture\Pong;
use Monadial\Nexus\Cluster\Tcp\Tests\Support\SpyTraceContextExtractor;
use Monadial\Nexus\Cluster\Tcp\Tests\Support\SpyTraceContextInjector;
use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\DeadLetterRef;
use Monadial\Nexus\Core\Actor\LocalActorRef;
use Monadial\Nexus\Core\Exception\AskTimeoutException;
use Monadial\Nexus\Core\Tests\Support\TestMailbox;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Monadial\Nexus\Observability\NoopObservability;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Serialization\Exception\MessageDeserializationException;
use Monadial\Nexus\Serialization\Msgpack\MessagePackMessageSerializer;
use Monadial\Nexus\Serialization\TypeRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(FrameIngress::class)]
#[CoversClass(LocalActorRegistry::class)]
#[CoversClass(LocalDelivery::class)]
#[CoversClass(ClusterMessageCodec::class)]
#[CoversClass(TcpAskRegistry::class)]
#[CoversClass(ClusterRef::class)]
#[CoversClass(ClusterRefFactory::class)]
#[CoversClass(ClusterReplyRef::class)]
#[CoversClass(InboxRouter::class)]
#[CoversClass(RecordingOutboundSink::class)]
final class ClusterMessagingTest extends TestCase
{
    private const string PATH = '/user/greeter';

    #[Test]
    public function registryExposesAndResolvesLocalRef(): void
    {
        $registry = new LocalActorRegistry();
        [$ref] = $this->localRef(self::PATH);
        $registry->expose($ref);

        self::assertSame($ref, $registry->resolve(self::PATH));
        self::assertNull($registry->resolve('/user/unknown'));
    }

    #[Test]
    public function registryRejectsNonLocalRef(): void
    {
        $registry = new LocalActorRegistry();

        $this->expectException(InvalidArgumentException::class);

        $registry->expose(new DeadLetterRef());
    }

    #[Test]
    public function tellToSelfDeliversLocallyWithoutSendingAFrame(): void
    {
        $registry = new LocalActorRegistry();
        [$ref, $mailbox] = $this->localRef(self::PATH);
        $registry->expose($ref);

        $sink = new RecordingOutboundSink();
        $node = $this->node('node-a');
        $clusterRef = new ClusterRef(
            $node,
            $node,
            ActorPath::fromString(self::PATH),
            $sink,
            new LocalDelivery($registry),
            new TcpAskRegistry(new TestRuntime()),
            $this->codec(),
            new SpyTraceContextInjector(),
            static fn(): bool => true,
        );

        $clusterRef->tell(new Ping('hi'));

        self::assertTrue($sink->isEmpty());

        $delivered = $mailbox->dequeue();
        self::assertNotNull($delivered);
        self::assertInstanceOf(Ping::class, $delivered->message);
        self::assertSame('hi', $delivered->message->text);
    }

    #[Test]
    public function tellToRemoteSerializesPayloadAndSendsViaSink(): void
    {
        $sink = new RecordingOutboundSink();
        $trace = new SpyTraceContextInjector();
        $target = $this->node('node-b');
        $clusterRef = new ClusterRef(
            $this->node('node-a'),
            $target,
            ActorPath::fromString(self::PATH),
            $sink,
            new LocalDelivery(new LocalActorRegistry()),
            new TcpAskRegistry(new TestRuntime()),
            $this->codec(),
            $trace,
            static fn(): bool => true,
        );

        $clusterRef->tell(new Ping('remote'));

        self::assertSame(1, $sink->count());
        $sent = $sink->sent()[0];
        self::assertSame($target, $sent['address']);
        $payload = $sent['payload'];
        self::assertSame(self::PATH, $payload->targetPath);
        self::assertSame('test.ping', $payload->messageType);
        self::assertNull($payload->correlationId);
        self::assertNull($payload->replyPath);
        self::assertSame(['traceparent' => 'spy-trace'], $payload->trace);
        self::assertSame(1, $trace->injectCount);

        $decoded = $this->codec()->decode($payload->messageType, $payload->body);
        self::assertInstanceOf(Ping::class, $decoded);
        self::assertSame('remote', $decoded->text);
    }

    #[Test]
    public function askRoundTripResolvesFutureFromReplyFrame(): void
    {
        $codec = $this->codec();
        $nodeA = $this->node('node-a');
        $nodeB = $this->node('node-b');

        $registryB = new LocalActorRegistry();
        [$responder, $responderMailbox] = $this->localRef(self::PATH);
        $registryB->expose($responder);

        $askRegistry = new TcpAskRegistry(new TestRuntime());
        $replySink = new RecordingOutboundSink();

        // Node A's inbound router resolves replies against A's ask registry.
        $requesterRouter = new InboxRouter(
            new LocalDelivery(new LocalActorRegistry()),
            $askRegistry,
            $codec,
            new RecordingOutboundSink(),
            new NoopTraceContextExtractor(),
        );

        // Node B's inbound router delivers requests to B's actors and replies via replySink.
        $responderRouter = new InboxRouter(
            new LocalDelivery($registryB),
            new TcpAskRegistry(new TestRuntime()),
            $codec,
            $replySink,
            new NoopTraceContextExtractor(),
        );

        // B's replies loop back into A's inbound router.
        $replySink->onInbound(static fn(NodeAddress $t, MessagePayload $p) => $requesterRouter->route($nodeB, $p));

        // A's requests loop into B's inbound router.
        $requestSink = new RecordingOutboundSink();
        $requestSink->onInbound(static fn(NodeAddress $t, MessagePayload $p) => $responderRouter->route($nodeA, $p));

        $clusterRef = new ClusterRef(
            $nodeA,
            $nodeB,
            ActorPath::fromString(self::PATH),
            $requestSink,
            new LocalDelivery(new LocalActorRegistry()),
            $askRegistry,
            $codec,
            new SpyTraceContextInjector(),
            static fn(): bool => true,
        );

        $future = $clusterRef->ask(new Ping('ping?'), Duration::seconds(5));

        // The request has been delivered to B's actor with a reply sender attached.
        $received = $responderMailbox->dequeue();
        self::assertNotNull($received);
        self::assertInstanceOf(Ping::class, $received->message);
        self::assertInstanceOf(ClusterReplyRef::class, $received->senderRef);

        // Actor replies; the reply frame loops back and resolves the ask.
        $received->senderRef->tell(new Pong('pong!'));

        $reply = $future->await();
        self::assertInstanceOf(Pong::class, $reply);
        self::assertSame('pong!', $reply->text);
    }

    #[Test]
    public function askTimesOutWhenNoReplyArrives(): void
    {
        $runtime = new TestRuntime();
        $askRegistry = new TcpAskRegistry($runtime);
        $sink = new RecordingOutboundSink();

        $clusterRef = new ClusterRef(
            $this->node('node-a'),
            $this->node('node-b'),
            ActorPath::fromString(self::PATH),
            $sink,
            new LocalDelivery(new LocalActorRegistry()),
            $askRegistry,
            $this->codec(),
            new SpyTraceContextInjector(),
            static fn(): bool => true,
        );

        $future = $clusterRef->ask(new Ping('anyone?'), Duration::seconds(5));

        self::assertSame(1, $sink->count());
        $payload = $sink->last();
        self::assertNotNull($payload);
        self::assertNotNull($payload->correlationId);
        self::assertNotNull($payload->replyPath);
        self::assertSame(1, $askRegistry->count());

        $runtime->advanceTime(Duration::seconds(5));

        try {
            $future->await();
            self::fail('Expected AskTimeoutException to be thrown');
        } catch (AskTimeoutException) {
            // Expected timeout exception
        }

        self::assertSame(0, $askRegistry->count());
    }

    #[Test]
    public function failAllForNodeFailsInFlightAsksToThatNodeOnly(): void
    {
        $runtime = new TestRuntime();
        $registry = new TcpAskRegistry($runtime);

        $nodeA = $this->node('node-a');
        $nodeB = $this->node('node-b');

        $a1 = $registry->register('a1', Duration::seconds(30), ActorPath::fromString(self::PATH), $nodeA);
        $a2 = $registry->register('a2', Duration::seconds(30), ActorPath::fromString(self::PATH), $nodeA);
        $registry->register('b1', Duration::seconds(30), ActorPath::fromString(self::PATH), $nodeB);

        self::assertSame(3, $registry->count());

        $failed = $registry->failAllForNode($nodeA);

        self::assertSame(2, $failed, 'both node-a asks failed');
        self::assertSame(1, $registry->count(), 'the node-b ask is untouched');
        self::assertTrue($registry->has('b1'));

        foreach (['a1' => $a1, 'a2' => $a2] as $future) {
            try {
                $future->await();
                self::fail('Expected PeerUnreachableException for a node-a ask');
            } catch (PeerUnreachableException) {
                // Expected: the reply can never arrive over the dead link.
            }
        }
    }

    #[Test]
    public function inboxRouterDeliversDecodedTellToLocalActor(): void
    {
        $registry = new LocalActorRegistry();
        [$ref, $mailbox] = $this->localRef(self::PATH);
        $registry->expose($ref);

        $extractor = new SpyTraceContextExtractor();
        $router = new InboxRouter(
            new LocalDelivery($registry),
            new TcpAskRegistry(new TestRuntime()),
            $this->codec(),
            new RecordingOutboundSink(),
            $extractor,
        );

        $payload = new MessagePayload(
            targetPath: self::PATH,
            messageType: 'test.ping',
            body: $this->codec()->encode(new Ping('inbound'))->body,
            correlationId: null,
            replyPath: null,
            trace: ['traceparent' => 'abc'],
        );

        $router->route($this->node('node-b'), $payload);

        $delivered = $mailbox->dequeue();
        self::assertNotNull($delivered);
        self::assertInstanceOf(Ping::class, $delivered->message);
        self::assertSame('inbound', $delivered->message->text);
        self::assertSame(1, $extractor->extractCount);
        self::assertSame([['traceparent' => 'abc']], $extractor->extracted);
    }

    #[Test]
    public function inboundAskInjectsReplySenderThatSendsReplyViaSink(): void
    {
        $registry = new LocalActorRegistry();
        [$ref, $mailbox] = $this->localRef(self::PATH);
        $registry->expose($ref);

        $replySink = new RecordingOutboundSink();
        $nodeA = $this->node('node-a');
        $router = new InboxRouter(
            new LocalDelivery($registry),
            new TcpAskRegistry(new TestRuntime()),
            $this->codec(),
            $replySink,
            new NoopTraceContextExtractor(),
        );

        $replyPath = (string) $nodeA->temporaryAskReplyPath('corr-123');
        $payload = new MessagePayload(
            targetPath: self::PATH,
            messageType: 'test.ping',
            body: $this->codec()->encode(new Ping('ask'))->body,
            correlationId: 'corr-123',
            replyPath: $replyPath,
            trace: [],
        );

        $router->route($nodeA, $payload);

        $received = $mailbox->dequeue();
        self::assertNotNull($received);
        self::assertInstanceOf(ClusterReplyRef::class, $received->senderRef);

        $received->senderRef->tell(new Pong('answer'));

        self::assertSame(1, $replySink->count());
        $sent = $replySink->sent()[0];
        self::assertSame($nodeA, $sent['address']);
        self::assertSame($replyPath, $sent['payload']->targetPath);
        self::assertSame('corr-123', $sent['payload']->correlationId);
        self::assertNull($sent['payload']->replyPath);

        $decoded = $this->codec()->decode($sent['payload']->messageType, $sent['payload']->body);
        self::assertInstanceOf(Pong::class, $decoded);
        self::assertSame('answer', $decoded->text);
    }

    #[Test]
    public function inboundAskWithMalformedReplyPathIsDroppedNotDelivered(): void
    {
        // I5: replyPath is supplied verbatim by a remote peer. A path that is not the origin node's
        // own temporary ask-reply path is not a legitimate reply target — it must be dropped (never
        // delivered, never nacked), not echoed into a ClusterReplyRef that would later throw when its
        // path() calls ActorPath::fromString() on garbage.
        $registry = new LocalActorRegistry();
        [$ref, $mailbox] = $this->localRef(self::PATH);
        $registry->expose($ref);

        $router = new InboxRouter(
            new LocalDelivery($registry),
            new TcpAskRegistry(new TestRuntime()),
            $this->codec(),
            new RecordingOutboundSink(),
            new NoopTraceContextExtractor(),
        );

        $payload = new MessagePayload(
            targetPath: self::PATH,
            messageType: 'test.ping',
            body: $this->codec()->encode(new Ping('ask'))->body,
            correlationId: 'corr-evil',
            replyPath: 'not-even-a-path::../../etc/passwd',
            trace: [],
        );

        $router->route($this->node('node-a'), $payload);

        self::assertNull($mailbox->dequeue(), 'a malformed reply path must not be delivered');
        self::assertSame(1, $router->drops());
    }

    #[Test]
    public function inboundAskWithReplyPathForADifferentNodeIsDropped(): void
    {
        // I5: even a well-FORMED actor path that is not under the ORIGIN node's own prefix is rejected —
        // we only ever reply back to the origin, so a reply target belonging to another node is bogus.
        $registry = new LocalActorRegistry();
        [$ref, $mailbox] = $this->localRef(self::PATH);
        $registry->expose($ref);

        $router = new InboxRouter(
            new LocalDelivery($registry),
            new TcpAskRegistry(new TestRuntime()),
            $this->codec(),
            new RecordingOutboundSink(),
            new NoopTraceContextExtractor(),
        );

        // A syntactically valid ask-reply path, but minted for node-b while the frame arrives from node-a.
        $foreignReplyPath = (string) $this->node('node-b')->temporaryAskReplyPath('corr-123');
        $payload = new MessagePayload(
            targetPath: self::PATH,
            messageType: 'test.ping',
            body: $this->codec()->encode(new Ping('ask'))->body,
            correlationId: 'corr-123',
            replyPath: $foreignReplyPath,
            trace: [],
        );

        $router->route($this->node('node-a'), $payload);

        self::assertNull($mailbox->dequeue(), 'a reply path for a different node must not be delivered');
        self::assertSame(1, $router->drops());
    }

    #[Test]
    public function askSurfacesAskCapacityExceededExceptionWhenRegistryIsFull(): void
    {
        // B4: the documented capacity-rejection contract is the SPECIFIC exception type. A registry at
        // capacity must surface AskCapacityExceededException to the caller — not swallow it, not wrap it.
        $askRegistry = new TcpAskRegistry(new TestRuntime(), maxPending: 0);

        $clusterRef = new ClusterRef(
            $this->node('node-a'),
            $this->node('node-b'),
            ActorPath::fromString(self::PATH),
            new RecordingOutboundSink(),
            new LocalDelivery(new LocalActorRegistry()),
            $askRegistry,
            $this->codec(),
            new SpyTraceContextInjector(),
            static fn(): bool => true,
        );

        $this->expectException(AskCapacityExceededException::class);

        $clusterRef->ask(new Ping('over-capacity'), Duration::seconds(5));
    }

    #[Test]
    public function unroutableTargetIncrementsDropCounter(): void
    {
        $router = new InboxRouter(
            new LocalDelivery(new LocalActorRegistry()),
            new TcpAskRegistry(new TestRuntime()),
            $this->codec(),
            new RecordingOutboundSink(),
            new NoopTraceContextExtractor(),
        );

        $payload = new MessagePayload(
            targetPath: '/user/nobody',
            messageType: 'test.ping',
            body: $this->codec()->encode(new Ping('lost'))->body,
            correlationId: null,
            replyPath: null,
            trace: [],
        );

        $router->route($this->node('node-b'), $payload);

        self::assertSame(1, $router->drops());
    }

    #[Test]
    public function replyWithNoPendingCorrelationIncrementsDropCounter(): void
    {
        $router = new InboxRouter(
            new LocalDelivery(new LocalActorRegistry()),
            new TcpAskRegistry(new TestRuntime()),
            $this->codec(),
            new RecordingOutboundSink(),
            new NoopTraceContextExtractor(),
        );

        $payload = new MessagePayload(
            targetPath: '/user/whatever',
            messageType: 'test.pong',
            body: $this->codec()->encode(new Pong('late'))->body,
            correlationId: 'unknown-corr',
            replyPath: null,
            trace: [],
        );

        $router->route($this->node('node-b'), $payload);

        self::assertSame(1, $router->drops());
    }

    #[Test]
    public function decodeRejectsLiteralClassNameFromTheWire(): void
    {
        // Security: the shared MessagePack serializer falls back to treating an unregistered
        // type name as a literal class name. On the cluster network path that would let a remote
        // peer drive instantiation of any autoloadable class by naming it on the wire. The cluster
        // codec is registry-strict — an unregistered type (here, the raw FQCN instead of the
        // registered 'test.ping') is rejected, not instantiated.
        $body = $this->codec()->encode(new Ping('x'))->body;

        $this->expectException(MessageDeserializationException::class);
        $this->codec()->decode(Ping::class, $body);
    }

    #[Test]
    public function localDeliveryReportsUnroutableForUnknownPath(): void
    {
        $delivery = new LocalDelivery(new LocalActorRegistry());

        self::assertSame(
            DeliveryOutcome::Unroutable,
            $delivery->deliver('/user/ghost', new Ping('x'), null),
        );
    }

    #[Test]
    public function selfNodeUnroutableTellIsCountedViaLocalDelivery(): void
    {
        $localDelivery = new LocalDelivery(new LocalActorRegistry()); // empty — actor not exposed
        $node = $this->node('node-a');

        $clusterRef = new ClusterRef(
            $node,
            $node, // same node = self-node path
            ActorPath::fromString(self::PATH),
            new RecordingOutboundSink(),
            $localDelivery,
            new TcpAskRegistry(new TestRuntime()),
            $this->codec(),
            new SpyTraceContextInjector(),
            static fn(): bool => true,
        );

        $clusterRef->tell(new Ping('lost'));

        // The tell is self-node: it goes straight to LocalDelivery without a frame.
        // Since the actor is not in the registry, LocalDelivery must count the drop.
        self::assertSame(1, $localDelivery->drops());
    }

    #[Test]
    public function factoryBuildsRefTargetingGivenNode(): void
    {
        $sink = new RecordingOutboundSink();
        $factory = new ClusterRefFactory(
            $this->node('node-a'),
            $sink,
            new LocalDelivery(new LocalActorRegistry()),
            new TcpAskRegistry(new TestRuntime()),
            $this->codec(),
        );

        $ref = $factory->refFor($this->node('node-b'), ActorPath::fromString(self::PATH));
        $ref->tell(new Ping('via-factory'));

        self::assertSame(self::PATH, (string) $ref->path());
        self::assertTrue($ref->isAlive());
        self::assertSame(1, $sink->count());
    }

    private function codec(): ClusterMessageCodec
    {
        $registry = new TypeRegistry();
        $registry->registerFromAttribute(Ping::class);
        $registry->registerFromAttribute(Pong::class);

        return new ClusterMessageCodec(new MessagePackMessageSerializer($registry), $registry);
    }

    private function node(string $name): NodeAddress
    {
        return new NodeAddress('test-cluster', 'dc1', 'nexus', $name);
    }

    /**
     * @return array{LocalActorRef<object>, TestMailbox}
     */
    private function localRef(string $path): array
    {
        $mailbox = TestMailbox::unbounded();

        return [
            new LocalActorRef(
                ActorPath::fromString($path),
                $mailbox,
                static fn(): bool => true,
                new TestRuntime(),
                new NoopObservability(),
            ),
            $mailbox,
        ];
    }
}
