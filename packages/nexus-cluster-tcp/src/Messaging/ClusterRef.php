<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Messaging;

use Closure;
use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\Tcp\Exception\AskCapacityExceededException;
use Monadial\Nexus\Cluster\Tcp\Payload\MessagePayload;
use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Exception\AskTimeoutException;
use Monadial\Nexus\Core\Message\SystemMessage;
use Monadial\Nexus\Observability\Metric\Meter;
use Monadial\Nexus\Observability\Metric\NoopMeter;
use Monadial\Nexus\Observability\Trace\NoopSpan;
use Monadial\Nexus\Observability\Trace\NoopTracer;
use Monadial\Nexus\Observability\Trace\Span;
use Monadial\Nexus\Observability\Trace\SpanKind;
use Monadial\Nexus\Observability\Trace\StatusCode;
use Monadial\Nexus\Observability\Trace\Tracer;
use Monadial\Nexus\Runtime\Async\Future;
use Monadial\Nexus\Runtime\Duration;
use Override;
use Throwable;

/**
 * @psalm-api
 *
 * Location-transparent reference to an actor living on a cluster node.
 *
 * A send to the local node short-circuits straight to {@see InboundDelivery} with no frame
 * on the wire. A send to a remote node serializes the message into a {@see MessagePayload}
 * (trace context injected through the seam) and hands it to the {@see OutboundSink}.
 * {@see ask()} registers a correlation slot in the {@see TcpAskRegistry}, stamps a
 * `replyPath` derived from the sending node's address, and returns a {@see Future} that
 * resolves on the reply frame or fails with {@see AskTimeoutException} after one RTT.
 *
 * `cluster.send` and `cluster.ask` spans are opened around each outbound publish; both are
 * swallow-safe so a broken tracer never disrupts message delivery.
 * Self-node short-circuits produce no span.
 *
 * @template T of object
 * @implements ActorRef<T>
 */
final readonly class ClusterRef implements ActorRef
{
    /**
     * @param Closure(): bool $aliveChecker
     */
    public function __construct(
        private NodeAddress $self,
        private NodeAddress $target,
        private ActorPath $targetPath,
        private OutboundSink $sink,
        private InboundDelivery $localDelivery,
        private TcpAskRegistry $askRegistry,
        private ClusterMessageCodec $codec,
        private TraceContextInjector $trace,
        private Closure $aliveChecker,
        private Tracer $tracer = new NoopTracer(),
        private Meter $meter = new NoopMeter(),
    ) {}

    /**
     * @param T|SystemMessage $message
     */
    #[Override]
    public function tell(object $message): void
    {
        $encoded = $this->codec->encode($message);

        if ($this->targetsSelf()) {
            $_ = $this->localDelivery->deliver((string) $this->targetPath, $message, null);
            $this->safely(fn(): mixed => $this->meter
                ->counter(
                    'nexus.cluster.messages.local_shortcircuit',
                    '{message}',
                    'Self-node tells short-circuited locally',
                )
                ->add(1, ['nexus.message.type' => $encoded->type]));

            return;
        }

        $span = $this->safeStartSpan('cluster.send', SpanKind::Producer, [
            'messaging.system' => 'nexus-tcp',
            'nexus.cluster.peer' => $this->target->toPathPrefix(),
            'nexus.message.type' => $encoded->type,
        ]);

        // Inject AFTER starting the span so the propagated trace-context references the
        // cluster.send span itself (the tracer activates it) — otherwise the remote
        // cluster.receive parents to the pre-span context and the trace is not chained.
        $trace = $this->safeInject();

        try {
            $this->sink->send($this->target, new MessagePayload(
                targetPath: (string) $this->targetPath,
                messageType: $encoded->type,
                body: $encoded->body,
                correlationId: null,
                replyPath: null,
                trace: $trace,
            ));
            $this->safely(fn(): mixed => $this->meter
                ->counter('nexus.cluster.messages.sent', '{message}', 'Remote cluster tells sent')
                ->add(1, ['nexus.message.type' => $encoded->type]));
        } catch (Throwable $e) {
            $this->safeRecordError($span, $e);

            throw $e;
        } finally {
            $this->safeEnd($span);
        }
    }

    /**
     * @template R of object
     * @param T $message
     * @return Future<R>
     *
     * @throws AskTimeoutException When no reply arrives within `$timeout` (thrown on await).
     */
    #[Override]
    public function ask(object $message, Duration $timeout): Future
    {
        $correlationId = bin2hex(random_bytes(16));
        $replyPath = $this->self->temporaryAskReplyPath($correlationId);

        $encoded = $this->codec->encode($message);

        $span = $this->safeStartSpan('cluster.ask', SpanKind::Producer, [
            'messaging.system' => 'nexus-tcp',
            'nexus.cluster.peer' => $this->target->toPathPrefix(),
            'nexus.message.type' => $encoded->type,
        ]);

        // Inject AFTER starting the span so the reply/receive side chains to this cluster.ask
        // span rather than to the pre-span context (see tell()).
        $trace = $this->safeInject();

        try {
            /** @var Future<R> $future */
            $future = $this->askRegistry->register($correlationId, $timeout, $this->targetPath, $this->target);

            $this->safely(fn(): mixed => $this->meter
                ->counter('nexus.cluster.asks.sent', '{message}', 'Remote cluster asks sent')
                ->add(1, ['nexus.message.type' => $encoded->type]));

            $this->sink->send($this->target, new MessagePayload(
                targetPath: (string) $this->targetPath,
                messageType: $encoded->type,
                body: $encoded->body,
                correlationId: $correlationId,
                replyPath: (string) $replyPath,
                trace: $trace,
            ));
        } catch (AskCapacityExceededException $e) {
            $this->safely(fn(): mixed => $this->meter
                ->counter(
                    'nexus.cluster.asks.capacity_rejected',
                    '{message}',
                    'Cluster asks rejected due to registry capacity',
                )
                ->add(1, ['nexus.message.type' => $encoded->type]));
            $this->safeRecordError($span, $e);

            throw $e;
        } catch (Throwable $e) {
            $this->safeRecordError($span, $e);

            throw $e;
        } finally {
            $this->safeEnd($span);
        }

        return $future;
    }

    #[Override]
    public function path(): ActorPath
    {
        return $this->targetPath;
    }

    #[Override]
    public function isAlive(): bool
    {
        return ($this->aliveChecker)();
    }

    private function targetsSelf(): bool
    {
        return $this->target->toPathPrefix() === $this->self->toPathPrefix();
    }

    /** @return array<string, string> */
    private function safeInject(): array
    {
        try {
            return $this->trace->inject();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param array<string, scalar> $attributes
     */
    private function safeStartSpan(string $name, SpanKind $kind, array $attributes): Span
    {
        try {
            return $this->tracer->startSpan($name, $kind, $attributes);
        } catch (Throwable) {
            return new NoopSpan();
        }
    }

    private function safeRecordError(Span $span, Throwable $e): void
    {
        try {
            $span->recordException($e);
            $span->setStatus(StatusCode::Error, $e->getMessage());
        } catch (Throwable) {
        }
    }

    private function safeEnd(Span $span): void
    {
        try {
            $span->end();
        } catch (Throwable) {
        }
    }

    /**
     * @param callable(): mixed $fn
     */
    private function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable) {
            // Telemetry must never break cluster operations.
        }
    }
}
