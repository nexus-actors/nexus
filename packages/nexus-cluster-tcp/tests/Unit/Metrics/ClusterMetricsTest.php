<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Tests\Unit\Metrics;

use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\Tcp\ClusterTopology;
use Monadial\Nexus\Cluster\Tcp\Frame;
use Monadial\Nexus\Cluster\Tcp\Membership\MembershipActor;
use Monadial\Nexus\Cluster\Tcp\Membership\MembershipService;
use Monadial\Nexus\Cluster\Tcp\Membership\Message\HandshakeReceived;
use Monadial\Nexus\Cluster\Tcp\Membership\Message\PeerLinkClosed;
use Monadial\Nexus\Cluster\Tcp\Membership\NodeSuspected;
use Monadial\Nexus\Cluster\Tcp\Membership\PeerSelector;
use Monadial\Nexus\Cluster\Tcp\Membership\PhiAccrualDetector;
use Monadial\Nexus\Cluster\Tcp\Membership\SendGossip;
use Monadial\Nexus\Cluster\Tcp\Membership\TcpMembershipEffectInterpreter;
use Monadial\Nexus\Cluster\Tcp\Messaging\ClusterMessageCodec;
use Monadial\Nexus\Cluster\Tcp\Messaging\ClusterRef;
use Monadial\Nexus\Cluster\Tcp\Messaging\InboxRouter;
use Monadial\Nexus\Cluster\Tcp\Messaging\LocalActorRegistry;
use Monadial\Nexus\Cluster\Tcp\Messaging\LocalDelivery;
use Monadial\Nexus\Cluster\Tcp\Messaging\NoopTraceContextExtractor;
use Monadial\Nexus\Cluster\Tcp\Messaging\NoopTraceContextInjector;
use Monadial\Nexus\Cluster\Tcp\Messaging\RecordingOutboundSink;
use Monadial\Nexus\Cluster\Tcp\Messaging\TcpAskRegistry;
use Monadial\Nexus\Cluster\Tcp\Messaging\TraceContextInjector;
use Monadial\Nexus\Cluster\Tcp\NodeEndpoint;
use Monadial\Nexus\Cluster\Tcp\Payload\ControlFrameCodec;
use Monadial\Nexus\Cluster\Tcp\Payload\GossipPayload;
use Monadial\Nexus\Cluster\Tcp\Payload\Handshake;
use Monadial\Nexus\Cluster\Tcp\Payload\MessagePayload;
use Monadial\Nexus\Cluster\Tcp\Tests\Fixture\Ping;
use Monadial\Nexus\Cluster\Tcp\Tests\Fixture\Pong;
use Monadial\Nexus\Cluster\Tcp\Tests\Support\RecordingEffectInterpreter;
use Monadial\Nexus\Cluster\Tcp\Tests\Support\RecordingEventPublisher;
use Monadial\Nexus\Cluster\Tcp\Tests\Support\RecordingMeter;
use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\LocalActorRef;
use Monadial\Nexus\Core\Tests\Support\TestMailbox;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Monadial\Nexus\Observability\Metric\Counter;
use Monadial\Nexus\Observability\Metric\Histogram;
use Monadial\Nexus\Observability\Metric\Meter;
use Monadial\Nexus\Observability\Metric\NoopMeter;
use Monadial\Nexus\Observability\Metric\NoopObservableGauge;
use Monadial\Nexus\Observability\Metric\NoopUpDownCounter;
use Monadial\Nexus\Observability\Metric\ObservableGauge;
use Monadial\Nexus\Observability\Metric\UpDownCounter;
use Monadial\Nexus\Observability\NoopObservability;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Step\StepRuntime;
use Monadial\Nexus\Serialization\Msgpack\MessagePackMessageSerializer;
use Monadial\Nexus\Serialization\TypeRegistry;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

use function array_slice;

#[CoversClass(ClusterRef::class)]
#[CoversClass(InboxRouter::class)]
#[CoversClass(TcpAskRegistry::class)]
#[CoversClass(TcpMembershipEffectInterpreter::class)]
#[CoversClass(MembershipActor::class)]
final class ClusterMetricsTest extends TestCase
{
    private const string PATH = '/user/greeter';

    // -------------------------------------------------------------------------
    // ClusterRef — messages.sent
    // -------------------------------------------------------------------------

    #[Test]
    public function messagesSentIncrementsOnRemoteTell(): void
    {
        $meter = new RecordingMeter();
        $ref = $this->remoteRef(
            $this->node('node-a'),
            $this->node('node-b'),
            new RecordingOutboundSink(),
            meter: $meter,
        );

        $ref->tell(new Ping('hello'));

        self::assertSame(1, $meter->counterSum('nexus.cluster.messages.sent'));
        self::assertSame(0, $meter->counterSum('nexus.cluster.messages.local_shortcircuit'));
    }

    // -------------------------------------------------------------------------
    // ClusterRef — local_shortcircuit
    // -------------------------------------------------------------------------

    #[Test]
    public function localShortCircuitIncrementsOnSelfNodeTell(): void
    {
        $meter = new RecordingMeter();
        $node = $this->node('node-a');
        $ref = $this->selfRef($node, new RecordingOutboundSink(), meter: $meter);

        $ref->tell(new Ping('local'));

        self::assertSame(1, $meter->counterSum('nexus.cluster.messages.local_shortcircuit'));
        self::assertSame(0, $meter->counterSum('nexus.cluster.messages.sent'));
    }

    // -------------------------------------------------------------------------
    // ClusterRef — asks.sent + ask capacity rejected
    // -------------------------------------------------------------------------

    #[Test]
    public function asksSentIncrementsAfterSuccessfulRegistration(): void
    {
        $meter = new RecordingMeter();
        $runtime = new TestRuntime();
        $askRegistry = new TcpAskRegistry($runtime, meter: $meter);
        $ref = $this->remoteRefWithRegistry($this->node('node-a'), $this->node('node-b'), $askRegistry, meter: $meter);

        $ref->ask(new Ping('ask?'), Duration::seconds(5));

        self::assertSame(1, $meter->counterSum('nexus.cluster.asks.sent'));
        self::assertSame(0, $meter->counterSum('nexus.cluster.asks.capacity_rejected'));
    }

    #[Test]
    public function asksCapacityRejectedIncrementsWhenRegistryAtCapacity(): void
    {
        $meter = new RecordingMeter();
        $runtime = new TestRuntime();
        $askRegistry = new TcpAskRegistry($runtime, maxPending: 0, meter: $meter);
        $ref = $this->remoteRefWithRegistry($this->node('node-a'), $this->node('node-b'), $askRegistry, meter: $meter);

        try {
            $ref->ask(new Ping('overflow'), Duration::seconds(5));
        } catch (Throwable) {
            // Expected — registry is full.
        }

        self::assertSame(1, $meter->counterSum('nexus.cluster.asks.capacity_rejected'));
        self::assertSame(0, $meter->counterSum('nexus.cluster.asks.sent'));
    }

    // -------------------------------------------------------------------------
    // InboxRouter — messages.received + messages.unroutable
    // -------------------------------------------------------------------------

    #[Test]
    public function messagesReceivedIncrementsOnRoute(): void
    {
        $meter = new RecordingMeter();
        $registry = new LocalActorRegistry();
        [$ref] = $this->localRef(self::PATH);
        $registry->expose($ref);

        $router = $this->buildRouter(new LocalDelivery($registry), meter: $meter);

        $router->route($this->node('node-b'), $this->pingPayload());

        self::assertSame(1, $meter->counterSum('nexus.cluster.messages.received'));
        self::assertSame(0, $meter->counterSum('nexus.cluster.messages.unroutable'));
    }

    #[Test]
    public function messagesUnroutableIncrementsWhenNoActorAtPath(): void
    {
        $meter = new RecordingMeter();
        $router = $this->buildRouter(new LocalDelivery(new LocalActorRegistry()), meter: $meter);

        $router->route($this->node('node-b'), $this->pingPayload());

        self::assertSame(1, $meter->counterSum('nexus.cluster.messages.received'));
        self::assertSame(1, $meter->counterSum('nexus.cluster.messages.unroutable'));
    }

    // -------------------------------------------------------------------------
    // TcpAskRegistry — asks.resolved + ask.duration
    // -------------------------------------------------------------------------

    #[Test]
    public function asksResolvedAndDurationRecordedOnResolve(): void
    {
        $meter = new RecordingMeter();
        $runtime = new TestRuntime();
        $registry = new TcpAskRegistry($runtime, meter: $meter);

        $registry->register('corr-1', Duration::seconds(5), ActorPath::fromString(self::PATH), self::peer());
        $registry->resolve('corr-1', new Pong('reply'));

        self::assertSame(1, $meter->counterSum('nexus.cluster.asks.resolved'));
        self::assertGreaterThanOrEqual(0, $meter->histogramTotal('nexus.cluster.ask.duration'));
    }

    // -------------------------------------------------------------------------
    // TcpAskRegistry — asks.timed_out
    // -------------------------------------------------------------------------

    #[Test]
    public function asksTimedOutIncrementsWhenTimeoutFires(): void
    {
        $meter = new RecordingMeter();
        $runtime = new TestRuntime();
        $registry = new TcpAskRegistry($runtime, meter: $meter);

        $registry->register('corr-timeout', Duration::millis(100), ActorPath::fromString(self::PATH), self::peer());
        $runtime->advanceTime(Duration::millis(100));

        self::assertSame(1, $meter->counterSum('nexus.cluster.asks.timed_out'));
    }

    // -------------------------------------------------------------------------
    // TcpAskRegistry — asks.pending observable gauge
    // -------------------------------------------------------------------------

    #[Test]
    public function asksPendingGaugeReflectsLiveCount(): void
    {
        $meter = new RecordingMeter();
        $runtime = new TestRuntime();
        $registry = new TcpAskRegistry($runtime, meter: $meter);

        // Two registers trigger lazy gauge registration and populate the registry.
        $registry->register('corr-g1', Duration::seconds(5), ActorPath::fromString(self::PATH), self::peer());
        $registry->register('corr-g2', Duration::seconds(5), ActorPath::fromString(self::PATH), self::peer());

        self::assertSame(2, $meter->observableGaugeValue('nexus.cluster.asks.pending'));
    }

    // -------------------------------------------------------------------------
    // MembershipActor — nodes.suspected
    // -------------------------------------------------------------------------

    #[Test]
    public function nodesSuspectedIncrementsOnNodeSuspectedEvent(): void
    {
        $meter = new RecordingMeter();
        $stepRuntime = new StepRuntime();
        $system = ActorSystem::create('membership-metrics-test', $stepRuntime, clock: $stepRuntime->clock());

        $effects = new RecordingEffectInterpreter();
        $events = new RecordingEventPublisher();
        $peer = new NodeAddress('production', 'eu', 'payments', 'node-2');
        $peerEndpoint = NodeEndpoint::fromString('10.0.0.2:7355');

        $topology = ClusterTopology::create(
            clusterName: 'production',
            self: new NodeAddress('production', 'eu', 'payments', 'node-1'),
            bindEndpoint: NodeEndpoint::fromString('127.0.0.1:7355'),
            advertiseEndpoint: NodeEndpoint::fromString('10.0.0.1:7355'),
            seeds: [NodeEndpoint::fromString('10.0.0.9:7355')],
        );

        $selector = new class implements PeerSelector {
            #[Override]
            public function select(array $peers, int $count): array
            {
                return array_slice($peers, 0, $count);
            }
        };

        $actor = new MembershipActor(
            new MembershipService($topology),
            new PhiAccrualDetector(),
            $selector,
            $effects,
            $events,
            $stepRuntime->clock(),
            meter: $meter,
        );

        $ref = $system->spawn($actor->props(), 'membership-metrics');
        $stepRuntime->drain();

        // Add peer via handshake, then trigger suspicion via unexpected link close.
        $ref->tell(new HandshakeReceived(
            $peer,
            $peerEndpoint,
            new Handshake(
                'production',
                [
                    'application' => $peer->application,
                    'cluster' => $peer->cluster,
                    'datacenter' => $peer->datacenter,
                    'node' => $peer->node,
                ],
                (string) $peerEndpoint,
            ),
            $stepRuntime->clock()->now(),
        ));
        $stepRuntime->drain();

        $ref->tell(new PeerLinkClosed($peer, intentional: false));
        $stepRuntime->drain();

        $suspected = $events->ofType(NodeSuspected::class);
        self::assertCount(1, $suspected, 'NodeSuspected event must have been published.');
        self::assertSame(1, $meter->counterSum('nexus.cluster.nodes.suspected'));
    }

    // -------------------------------------------------------------------------
    // TcpMembershipEffectInterpreter — gossip.rounds
    // -------------------------------------------------------------------------

    #[Test]
    public function gossipRoundsIncrementsOnSendGossip(): void
    {
        $meter = new RecordingMeter();

        $interpreter = new TcpMembershipEffectInterpreter(
            new ControlFrameCodec(),
            static function (string $prefix, Frame $frame): void {
                // Noop sender — we only care about the counter.
            },
            $meter,
        );

        $gossip = new GossipPayload([], []);
        $interpreter->interpret(new SendGossip(['/cluster/test/dc1/app/node-2'], $gossip));

        self::assertSame(1, $meter->counterSum('nexus.cluster.gossip.rounds'));
    }

    // -------------------------------------------------------------------------
    // Disabled path — NoopMeter produces no recording
    // -------------------------------------------------------------------------

    #[Test]
    public function noopMeterProducesNoRecordingAndDoesNotBreakOperations(): void
    {
        // With NoopMeter (default — no meter passed), operations must still complete.
        $sink = new RecordingOutboundSink();
        $ref = $this->remoteRef($this->node('node-a'), $this->node('node-b'), $sink);

        $ref->tell(new Ping('quiet'));

        self::assertSame(1, $sink->count());
    }

    // -------------------------------------------------------------------------
    // Swallow-safe — throwing Meter does not break tell
    // -------------------------------------------------------------------------

    #[Test]
    public function throwingMeterDoesNotBreakTell(): void
    {
        $throwingMeter = new class implements Meter {
            #[Override]
            public function counter(string $name, string $unit = '', string $description = ''): Counter
            {
                throw new RuntimeException('meter broken');
            }

            #[Override]
            public function upDownCounter(string $name, string $unit = '', string $description = ''): UpDownCounter
            {
                return new NoopUpDownCounter();
            }

            #[Override]
            public function histogram(string $name, string $unit = '', string $description = ''): Histogram
            {
                throw new RuntimeException('meter broken');
            }

            #[Override]
            public function observableGauge(
                string $name,
                callable $callback,
                string $unit = '',
                string $description = '',
            ): ObservableGauge {
                return new NoopObservableGauge();
            }
        };

        $sink = new RecordingOutboundSink();
        $ref = $this->remoteRef($this->node('node-a'), $this->node('node-b'), $sink, meter: $throwingMeter);

        // Must not throw even though meter throws.
        $ref->tell(new Ping('resilient'));

        self::assertSame(1, $sink->count());
    }

    // -------------------------------------------------------------------------
    // Carry-forward fix 1 — throwing injector does not break tell
    // -------------------------------------------------------------------------

    #[Test]
    public function throwingInjectorDoesNotBreakTell(): void
    {
        $throwingInjector = new class implements TraceContextInjector {
            /**
             * @return array<string, string>
             */
            #[Override]
            public function inject(): array
            {
                throw new RuntimeException('injector broken');
            }
        };

        $sink = new RecordingOutboundSink();
        $ref = $this->remoteRefWithInjector($this->node('node-a'), $this->node('node-b'), $sink, $throwingInjector);

        // Must not throw — safeInject() catches the exception and returns [].
        $ref->tell(new Ping('resilient-injector'));

        self::assertSame(1, $sink->count());
    }

    #[Test]
    public function throwingInjectorDoesNotBreakAsk(): void
    {
        $throwingInjector = new class implements TraceContextInjector {
            /**
             * @return array<string, string>
             */
            #[Override]
            public function inject(): array
            {
                throw new RuntimeException('injector broken');
            }
        };

        $sink = new RecordingOutboundSink();
        $ref = $this->remoteRefWithInjector($this->node('node-a'), $this->node('node-b'), $sink, $throwingInjector);

        // Must not throw — safeInject() catches the exception and returns [].
        $ref->ask(new Ping('resilient-ask-injector'), Duration::seconds(5));

        self::assertSame(1, $sink->count());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function remoteRef(
        NodeAddress $self,
        NodeAddress $target,
        RecordingOutboundSink $sink,
        ?Meter $meter = null,
    ): ClusterRef {
        return new ClusterRef(
            $self,
            $target,
            ActorPath::fromString(self::PATH),
            $sink,
            new LocalDelivery(new LocalActorRegistry()),
            new TcpAskRegistry(new TestRuntime()),
            $this->codec(),
            new NoopTraceContextInjector(),
            static fn(): bool => true,
            meter: $meter ?? new NoopMeter(),
        );
    }

    private function selfRef(NodeAddress $self, RecordingOutboundSink $sink, ?Meter $meter = null): ClusterRef
    {
        $registry = new LocalActorRegistry();
        [$ref] = $this->localRef(self::PATH);
        $registry->expose($ref);

        return new ClusterRef(
            $self,
            $self,
            ActorPath::fromString(self::PATH),
            $sink,
            new LocalDelivery($registry),
            new TcpAskRegistry(new TestRuntime()),
            $this->codec(),
            new NoopTraceContextInjector(),
            static fn(): bool => true,
            meter: $meter ?? new NoopMeter(),
        );
    }

    private function remoteRefWithRegistry(
        NodeAddress $self,
        NodeAddress $target,
        TcpAskRegistry $askRegistry,
        ?Meter $meter = null,
    ): ClusterRef {
        return new ClusterRef(
            $self,
            $target,
            ActorPath::fromString(self::PATH),
            new RecordingOutboundSink(),
            new LocalDelivery(new LocalActorRegistry()),
            $askRegistry,
            $this->codec(),
            new NoopTraceContextInjector(),
            static fn(): bool => true,
            meter: $meter ?? new NoopMeter(),
        );
    }

    private function remoteRefWithInjector(
        NodeAddress $self,
        NodeAddress $target,
        RecordingOutboundSink $sink,
        TraceContextInjector $injector,
    ): ClusterRef {
        return new ClusterRef(
            $self,
            $target,
            ActorPath::fromString(self::PATH),
            $sink,
            new LocalDelivery(new LocalActorRegistry()),
            new TcpAskRegistry(new TestRuntime()),
            $this->codec(),
            $injector,
            static fn(): bool => true,
        );
    }

    private function buildRouter(LocalDelivery $delivery, ?Meter $meter = null): InboxRouter
    {
        return new InboxRouter(
            $delivery,
            new TcpAskRegistry(new TestRuntime()),
            $this->codec(),
            new RecordingOutboundSink(),
            new NoopTraceContextExtractor(),
            meter: $meter ?? new NoopMeter(),
        );
    }

    private function pingPayload(): MessagePayload
    {
        return new MessagePayload(
            targetPath: self::PATH,
            messageType: 'test.ping',
            body: $this->codec()->encode(new Ping('hello'))->body,
            correlationId: null,
            replyPath: null,
            trace: [],
        );
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

    private static function peer(): NodeAddress
    {
        return new NodeAddress('production', 'eu', 'payments', 'node-2');
    }
}
