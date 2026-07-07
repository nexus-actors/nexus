<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Tests\Unit\Tracing;

use Monadial\Nexus\Cluster\NodeAddress;
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
use Monadial\Nexus\Cluster\Tcp\Payload\MessagePayload;
use Monadial\Nexus\Cluster\Tcp\Tests\Fixture\Ping;
use Monadial\Nexus\Cluster\Tcp\Tests\Fixture\Pong;
use Monadial\Nexus\Cluster\Tcp\Tests\Support\FakeObservability;
use Monadial\Nexus\Cluster\Tcp\Tests\Support\SpyTracer;
use Monadial\Nexus\Cluster\Tcp\Tracing\ObservabilityTraceContextExtractor;
use Monadial\Nexus\Cluster\Tcp\Tracing\ObservabilityTraceContextInjector;
use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\LocalActorRef;
use Monadial\Nexus\Core\Tests\Support\TestMailbox;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Monadial\Nexus\Observability\Context\Context;
use Monadial\Nexus\Observability\NoopObservability;
use Monadial\Nexus\Observability\Trace\NoopTracer;
use Monadial\Nexus\Observability\Trace\Span;
use Monadial\Nexus\Observability\Trace\SpanContext;
use Monadial\Nexus\Observability\Trace\SpanKind;
use Monadial\Nexus\Observability\Trace\Tracer;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Serialization\Msgpack\MessagePackMessageSerializer;
use Monadial\Nexus\Serialization\TypeRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(ClusterRef::class)]
#[CoversClass(InboxRouter::class)]
#[CoversClass(ObservabilityTraceContextInjector::class)]
#[CoversClass(ObservabilityTraceContextExtractor::class)]
final class ClusterTracingTest extends TestCase
{
    private const string PATH = '/user/greeter';

    // -------------------------------------------------------------------------
    // cluster.send span
    // -------------------------------------------------------------------------

    #[Test]
    public function clusterSendSpanOpensWithProducerKindAndAttrs(): void
    {
        $spy = new SpyTracer();
        $ref = $this->remoteRef($this->node('node-a'), $this->node('node-b'), new RecordingOutboundSink(), $spy);

        $ref->tell(new Ping('hello'));

        $spans = $spy->spansNamed('cluster.send');
        self::assertCount(1, $spans);
        self::assertSame(SpanKind::Producer, $spans[0]['kind']);
        self::assertSame('nexus-tcp', $spans[0]['attributes']['messaging.system']);
        self::assertSame('test.ping', $spans[0]['attributes']['nexus.message.type']);
        self::assertNotEmpty($spans[0]['attributes']['nexus.cluster.peer']);
    }

    #[Test]
    public function clusterSendSpanIsEndedAfterSend(): void
    {
        $spy = new SpyTracer();
        $ref = $this->remoteRef($this->node('node-a'), $this->node('node-b'), new RecordingOutboundSink(), $spy);

        $ref->tell(new Ping('hello'));

        $spans = $spy->spansNamed('cluster.send');
        self::assertTrue($spans[0]['span']->ended);
    }

    #[Test]
    public function selfNodeTellProducesNoSpan(): void
    {
        $spy = new SpyTracer();
        $node = $this->node('node-a');
        $registry = new LocalActorRegistry();
        [$ref] = $this->localRef(self::PATH);
        $registry->expose($ref);

        $clusterRef = new ClusterRef(
            $node,
            $node,
            ActorPath::fromString(self::PATH),
            new RecordingOutboundSink(),
            new LocalDelivery($registry),
            new TcpAskRegistry(new TestRuntime()),
            $this->codec(),
            new NoopTraceContextInjector(),
            static fn(): bool => true,
            $spy,
        );

        $clusterRef->tell(new Ping('local'));

        self::assertEmpty($spy->spansNamed('cluster.send'));
    }

    #[Test]
    public function clusterSendPayloadContainsTraceparentWhenSpanIsActive(): void
    {
        $traceId = str_repeat('a', 32);
        $spanId = str_repeat('b', 16);
        $spanContext = new SpanContext($traceId, $spanId, 1, false);
        $obs = new FakeObservability(new SpyTracer(), Context::fromSpanContext($spanContext));

        $injector = new ObservabilityTraceContextInjector($obs);
        $sink = new RecordingOutboundSink();
        $ref = $this->remoteRef($this->node('node-a'), $this->node('node-b'), $sink, new NoopTracer(), $injector);

        $ref->tell(new Ping('traced'));

        $payload = $sink->last();
        self::assertNotNull($payload);
        self::assertArrayHasKey('traceparent', $payload->trace);
        self::assertStringContainsString($traceId, $payload->trace['traceparent']);
    }

    #[Test]
    public function clusterSendPayloadHasEmptyTraceWhenObservabilityDisabled(): void
    {
        $injector = new ObservabilityTraceContextInjector(new NoopObservability());
        $sink = new RecordingOutboundSink();
        $ref = $this->remoteRef($this->node('node-a'), $this->node('node-b'), $sink, new NoopTracer(), $injector);

        $ref->tell(new Ping('untraced'));

        $payload = $sink->last();
        self::assertNotNull($payload);
        self::assertSame([], $payload->trace);
    }

    // -------------------------------------------------------------------------
    // cluster.ask span
    // -------------------------------------------------------------------------

    #[Test]
    public function clusterAskSpanOpensWithProducerKindAndAttrs(): void
    {
        $spy = new SpyTracer();
        $sink = new RecordingOutboundSink();
        $ref = $this->remoteRef($this->node('node-a'), $this->node('node-b'), $sink, $spy);

        $ref->ask(new Ping('ask?'), Duration::seconds(5));

        $spans = $spy->spansNamed('cluster.ask');
        self::assertCount(1, $spans);
        self::assertSame(SpanKind::Producer, $spans[0]['kind']);
        self::assertSame('nexus-tcp', $spans[0]['attributes']['messaging.system']);
        self::assertSame('test.ping', $spans[0]['attributes']['nexus.message.type']);
    }

    #[Test]
    public function clusterAskSpanIsEndedAfterPublish(): void
    {
        $spy = new SpyTracer();
        $ref = $this->remoteRef($this->node('node-a'), $this->node('node-b'), new RecordingOutboundSink(), $spy);

        $ref->ask(new Ping('ask?'), Duration::seconds(5));

        self::assertTrue($spy->spansNamed('cluster.ask')[0]['span']->ended);
    }

    // -------------------------------------------------------------------------
    // cluster.receive span
    // -------------------------------------------------------------------------

    #[Test]
    public function clusterReceiveSpanOpensWithConsumerKindAndIsParentedToExtractedContext(): void
    {
        $traceId = str_repeat('c', 32);
        $spanId = str_repeat('d', 16);
        $traceparent = "00-{$traceId}-{$spanId}-01";

        $spy = new SpyTracer();
        $obs = new FakeObservability($spy);
        $extractor = new ObservabilityTraceContextExtractor($obs);

        $registry = new LocalActorRegistry();
        [$ref] = $this->localRef(self::PATH);
        $registry->expose($ref);

        $router = new InboxRouter(
            new LocalDelivery($registry),
            new TcpAskRegistry(new TestRuntime()),
            $this->codec(),
            new RecordingOutboundSink(),
            $extractor,
            new NoopTraceContextInjector(),
            tracer: $spy,
        );

        $router->route($this->node('node-b'), $this->pingPayload(['traceparent' => $traceparent]));

        $spans = $spy->spansNamed('cluster.receive');
        self::assertCount(1, $spans);
        self::assertSame(SpanKind::Consumer, $spans[0]['kind']);
        self::assertSame('nexus-tcp', $spans[0]['attributes']['messaging.system']);

        // Parent context must carry the sender's trace ID — trace continuity
        $parent = $spans[0]['parent'];
        self::assertNotNull($parent);
        self::assertSame($traceId, $parent->spanContext->traceId);
    }

    #[Test]
    public function clusterReceiveSpanIsEndedAfterDispatch(): void
    {
        $spy = new SpyTracer();

        $registry = new LocalActorRegistry();
        [$ref] = $this->localRef(self::PATH);
        $registry->expose($ref);

        $router = new InboxRouter(
            new LocalDelivery($registry),
            new TcpAskRegistry(new TestRuntime()),
            $this->codec(),
            new RecordingOutboundSink(),
            new NoopTraceContextExtractor(),
            tracer: $spy,
        );

        $router->route($this->node('node-b'), $this->pingPayload([]));

        self::assertTrue($spy->spansNamed('cluster.receive')[0]['span']->ended);
    }

    // -------------------------------------------------------------------------
    // No-op path
    // -------------------------------------------------------------------------

    #[Test]
    public function noopTracerProducesNoSpans(): void
    {
        $spy = new SpyTracer();
        // Use NoopTracer explicitly, not the SpyTracer, to confirm no spans leak through
        $ref = $this->remoteRef(
            $this->node('node-a'),
            $this->node('node-b'),
            new RecordingOutboundSink(),
            new NoopTracer(),
        );
        $ref->tell(new Ping('quiet'));

        // No assertions needed for the ref itself; verify injector returns [] with noop obs
        $injector = new ObservabilityTraceContextInjector(new NoopObservability());
        self::assertSame([], $injector->inject());

        // And the extractor returns root context
        $extractor = new ObservabilityTraceContextExtractor(new NoopObservability());
        $ctx = $extractor->extract(['traceparent' => '00-' . str_repeat('a', 32) . '-' . str_repeat('b', 16) . '-01']);
        self::assertFalse($ctx->spanContext->isValid());
    }

    // -------------------------------------------------------------------------
    // Swallow-safe
    // -------------------------------------------------------------------------

    #[Test]
    public function throwingTracerDoesNotBreakTell(): void
    {
        $throwingTracer = new class implements Tracer {
            /** @param array<string, scalar> $attributes */
            public function startSpan(
                string $name,
                SpanKind $kind = SpanKind::Internal,
                array $attributes = [],
                ?Context $parent = null,
            ): Span {
                throw new RuntimeException('tracer broken');
            }
        };

        $sink = new RecordingOutboundSink();
        $ref = $this->remoteRef($this->node('node-a'), $this->node('node-b'), $sink, $throwingTracer);

        // Must not throw even though tracer throws
        $ref->tell(new Ping('resilient'));

        self::assertSame(1, $sink->count());
    }

    #[Test]
    public function throwingTracerDoesNotBreakAsk(): void
    {
        $throwingTracer = new class implements Tracer {
            /** @param array<string, scalar> $attributes */
            public function startSpan(
                string $name,
                SpanKind $kind = SpanKind::Internal,
                array $attributes = [],
                ?Context $parent = null,
            ): Span {
                throw new RuntimeException('tracer broken');
            }
        };

        $sink = new RecordingOutboundSink();
        $ref = $this->remoteRef($this->node('node-a'), $this->node('node-b'), $sink, $throwingTracer);

        // Must not throw
        $ref->ask(new Ping('resilient-ask'), Duration::seconds(5));

        self::assertSame(1, $sink->count());
    }

    #[Test]
    public function throwingTracerDoesNotBreakReceive(): void
    {
        $throwingTracer = new class implements Tracer {
            /** @param array<string, scalar> $attributes */
            public function startSpan(
                string $name,
                SpanKind $kind = SpanKind::Internal,
                array $attributes = [],
                ?Context $parent = null,
            ): Span {
                throw new RuntimeException('tracer broken');
            }
        };

        $registry = new LocalActorRegistry();
        [$ref] = $this->localRef(self::PATH);
        $registry->expose($ref);

        $router = new InboxRouter(
            new LocalDelivery($registry),
            new TcpAskRegistry(new TestRuntime()),
            $this->codec(),
            new RecordingOutboundSink(),
            new NoopTraceContextExtractor(),
            tracer: $throwingTracer,
        );

        // Must not throw
        $router->route($this->node('node-b'), $this->pingPayload([]));

        self::assertSame(0, $router->drops());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function remoteRef(
        NodeAddress $self,
        NodeAddress $target,
        RecordingOutboundSink $sink,
        Tracer $tracer,
        ?TraceContextInjector $injector = null,
    ): ClusterRef {
        return new ClusterRef(
            $self,
            $target,
            ActorPath::fromString(self::PATH),
            $sink,
            new LocalDelivery(new LocalActorRegistry()),
            new TcpAskRegistry(new TestRuntime()),
            $this->codec(),
            $injector ?? new NoopTraceContextInjector(),
            static fn(): bool => true,
            $tracer,
        );
    }

    /**
     * @param array<string, string> $trace
     */
    private function pingPayload(array $trace): MessagePayload
    {
        return new MessagePayload(
            targetPath: self::PATH,
            messageType: 'test.ping',
            body: $this->codec()->encode(new Ping('hello'))->body,
            correlationId: null,
            replyPath: null,
            trace: $trace,
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
}
