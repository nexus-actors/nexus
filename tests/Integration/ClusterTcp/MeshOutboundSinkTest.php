<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\ClusterTcp;

use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\Tcp\DeliveryOutcome;
use Monadial\Nexus\Cluster\Tcp\MapEndpointResolver;
use Monadial\Nexus\Cluster\Tcp\Messaging\ClusterMessageCodec;
use Monadial\Nexus\Cluster\Tcp\Messaging\FrameIngress;
use Monadial\Nexus\Cluster\Tcp\Messaging\InboxRouter;
use Monadial\Nexus\Cluster\Tcp\Messaging\LocalActorRegistry;
use Monadial\Nexus\Cluster\Tcp\Messaging\LocalDelivery;
use Monadial\Nexus\Cluster\Tcp\Messaging\NoopTraceContextExtractor;
use Monadial\Nexus\Cluster\Tcp\Messaging\TcpAskRegistry;
use Monadial\Nexus\Cluster\Tcp\NodeEndpoint;
use Monadial\Nexus\Cluster\Tcp\Payload\MessagePayload;
use Monadial\Nexus\Cluster\Tcp\Payload\MessagePayloadCodec;
use Monadial\Nexus\Cluster\Tcp\Protocol\Frame;
use Monadial\Nexus\Cluster\Tcp\Tests\Fixture\Ping;
use Monadial\Nexus\Cluster\Tcp\Tests\Support\RecordingMeter;
use Monadial\Nexus\Cluster\Tcp\Transport\Loopback\LoopbackHub;
use Monadial\Nexus\Cluster\Tcp\Transport\Loopback\LoopbackMeshTransport;
use Monadial\Nexus\Cluster\Tcp\Transport\MeshOutboundSink;
use Monadial\Nexus\Cluster\Tcp\Transport\PeerLink;
use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\LocalActorRef;
use Monadial\Nexus\Core\Net\Host;
use Monadial\Nexus\Core\Net\Port;
use Monadial\Nexus\Core\Tests\Support\TestMailbox;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Monadial\Nexus\Observability\NoopObservability;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use Monadial\Nexus\Serialization\Msgpack\MessagePackMessageSerializer;
use Monadial\Nexus\Serialization\TypeRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MeshOutboundSink::class)]
#[CoversClass(FrameIngress::class)]
#[CoversClass(LocalDelivery::class)]
final class MeshOutboundSinkTest extends TestCase
{
    /**
     * TDD SCENARIO 6: MeshOutboundSink::send() encodes a MessagePayload into a Message frame,
     * which is decoded by FrameIngress on the receiving side and routed to the local actor.
     * End-to-end loopback over LoopbackMeshTransport.
     */
    #[Test]
    public function sendDeliversThroughFrameIngressToLocalActor(): void
    {
        $runtime = new FiberRuntime();
        $hub = new LoopbackHub();
        $nodeBTransport = new LoopbackMeshTransport($hub, $runtime);
        $nodeATransport = new LoopbackMeshTransport($hub, $runtime);
        $endpoint = new NodeEndpoint(Host::of('127.0.0.1'), Port::of(8100));

        $nodeA = new NodeAddress('test', 'dc1', 'nexus', 'node-a');
        $nodeB = new NodeAddress('test', 'dc1', 'nexus', 'node-b');

        // Node B's actor registry + router
        $registry = new LocalActorRegistry();
        $mailbox = TestMailbox::unbounded();
        $actorRef = new LocalActorRef(
            ActorPath::fromString('/user/greeter'),
            $mailbox,
            static fn(): bool => true,
            new TestRuntime(),
            new NoopObservability(),
        );
        $registry->expose($actorRef);

        $codec = $this->codec();
        $payloadCodec = new MessagePayloadCodec();

        $router = new InboxRouter(
            new LocalDelivery($registry),
            new TcpAskRegistry(new TestRuntime()),
            $codec,
            new MeshOutboundSink(
                new MapEndpointResolver([]),
                $nodeBTransport,
                $runtime,
                $payloadCodec,
                Duration::millis(10),
                Duration::millis(100),
            ),
            new NoopTraceContextExtractor(),
        );

        $ingress = new FrameIngress($router, $nodeA, $payloadCodec);

        // Node B serves: wire FrameIngress to every inbound link.
        $nodeBTransport->serve(
            $endpoint,
            static function (PeerLink $link) use ($ingress): void {
                $link->onFrame(static function (Frame $frame) use ($ingress): void {
                    $ingress->ingest($frame);
                });
            },
        );

        // Node A's outbound sink sends to node-b's endpoint.
        $resolver = new MapEndpointResolver([$nodeB->toPathPrefix() => $endpoint]);
        $sink = new MeshOutboundSink(
            $resolver,
            $nodeATransport,
            $runtime,
            $payloadCodec,
            Duration::millis(10),
            Duration::millis(100),
        );

        // Build a MessagePayload targeting the actor at /user/greeter on node-b.
        $encoded = $codec->encode(new Ping('hello'));
        $payload = new MessagePayload(
            targetPath: '/user/greeter',
            messageType: $encoded->type,
            body: $encoded->body,
            correlationId: null,
            replyPath: null,
            trace: [],
        );

        $sink->send($nodeB, $payload);

        $runtime->scheduleOnce(Duration::millis(100), static function () use ($runtime): void {
            $runtime->shutdown(Duration::seconds(1));
        });

        $runtime->run();

        $envelope = $mailbox->dequeue();
        self::assertNotNull($envelope);
        self::assertInstanceOf(Ping::class, $envelope->message);
        self::assertSame('hello', $envelope->message->text);
        self::assertSame(0, $sink->drops());
    }

    /**
     * TDD SCENARIO 7: When the EndpointResolver cannot resolve the target NodeAddress,
     * the message is dropped and the drop counter increments.
     */
    #[Test]
    public function unresolvableEndpointDropsMessageAndIncrementsCounter(): void
    {
        $hub = new LoopbackHub();
        $transport = new LoopbackMeshTransport($hub, new FiberRuntime());
        $unknownNode = new NodeAddress('test', 'dc1', 'nexus', 'unknown');

        $meter = new RecordingMeter();
        $sink = new MeshOutboundSink(
            new MapEndpointResolver([]),  // empty — no endpoint for unknownNode
            $transport,
            new FiberRuntime(),
            new MessagePayloadCodec(),
            Duration::millis(10),
            Duration::millis(100),
            meter: $meter,
        );

        $encoded = $this->codec()->encode(new Ping('lost'));
        $payload = new MessagePayload(
            targetPath: '/user/nobody',
            messageType: $encoded->type,
            body: $encoded->body,
            correlationId: null,
            replyPath: null,
            trace: [],
        );

        // REL-009: a no-route send returns Dropped and is counted as dropped — never as sent.
        self::assertSame(DeliveryOutcome::Dropped, $sink->send($unknownNode, $payload));
        self::assertSame(DeliveryOutcome::Dropped, $sink->send($unknownNode, $payload));

        self::assertSame(2, $sink->drops());
        self::assertSame(2, $meter->counterSum('nexus.cluster.frames.dropped'));
        self::assertSame(0, $meter->counterSum('nexus.cluster.frames.sent'));
    }

    /**
     * REL-009: a resolvable peer with no live link buffers the frame — the send is counted as
     * buffered (a pending, not-guaranteed admission), never as sent.
     */
    #[Test]
    public function disconnectedPeerBuffersAndCountsAsBuffered(): void
    {
        $hub = new LoopbackHub();
        // No server is serving the endpoint, so PeerConnection can never connect — every send buffers.
        $transport = new LoopbackMeshTransport($hub, new FiberRuntime());
        $node = new NodeAddress('test', 'dc1', 'nexus', 'offline');
        $endpoint = new NodeEndpoint(Host::of('127.0.0.1'), Port::of(8109));

        $meter = new RecordingMeter();
        $sink = new MeshOutboundSink(
            new MapEndpointResolver([$node->toPathPrefix() => $endpoint]),
            $transport,
            new FiberRuntime(),
            new MessagePayloadCodec(),
            Duration::seconds(60),  // long backoff so the link never re-establishes during the test
            Duration::seconds(120),
            meter: $meter,
        );

        $encoded = $this->codec()->encode(new Ping('queued'));
        $payload = new MessagePayload(
            targetPath: '/user/somebody',
            messageType: $encoded->type,
            body: $encoded->body,
            correlationId: null,
            replyPath: null,
            trace: [],
        );

        self::assertSame(DeliveryOutcome::Buffered, $sink->send($node, $payload));

        self::assertSame(1, $meter->counterSum('nexus.cluster.frames.buffered'));
        self::assertSame(0, $meter->counterSum('nexus.cluster.frames.sent'));
        self::assertSame(0, $meter->counterSum('nexus.cluster.frames.dropped'));
    }

    // -- helpers ---------------------------------------------------------------

    private function codec(): ClusterMessageCodec
    {
        $registry = new TypeRegistry();
        $registry->registerFromAttribute(Ping::class);

        return new ClusterMessageCodec(
            new MessagePackMessageSerializer($registry),
            $registry,
        );
    }
}
