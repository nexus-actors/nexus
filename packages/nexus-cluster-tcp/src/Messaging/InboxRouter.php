<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Messaging;

use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\Tcp\Payload\MessagePayload;
use Monadial\Nexus\Observability\Context\Context;
use Monadial\Nexus\Observability\Metric\Counter;
use Monadial\Nexus\Observability\Metric\Meter;
use Monadial\Nexus\Observability\Metric\NoopMeter;
use Monadial\Nexus\Observability\Trace\NoopSpan;
use Monadial\Nexus\Observability\Trace\NoopTracer;
use Monadial\Nexus\Observability\Trace\Span;
use Monadial\Nexus\Observability\Trace\SpanKind;
use Monadial\Nexus\Observability\Trace\StatusCode;
use Monadial\Nexus\Observability\Trace\Tracer;
use Monadial\Nexus\Serialization\Exception\MessageDeserializationException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function str_starts_with;

/**
 * @psalm-api
 *
 * Routes an inbound {@see MessagePayload} (already lifted off a frame by the transport in
 * C1.6b) to its destination on this node. The origin {@see NodeAddress} is supplied by the
 * caller — it knows which peer link delivered the frame — and is used as the reply target
 * for inbound asks.
 *
 * Payload shapes are distinguished by the correlation/reply fields:
 * - `correlationId === null`                      → a tell; deliver to the target actor.
 * - `correlationId` set, `replyPath` set          → an inbound ask; deliver with a
 *                                                    {@see ClusterReplyRef} as the sender.
 * - `correlationId` set, `replyPath === null`     → a reply to one of our asks; resolve the
 *                                                    {@see TcpAskRegistry}.
 *
 * Undeliverable payloads (unroutable target, unknown/late correlation, decode failure) are
 * counted and logged at debug — never nacked, per spec §7.
 *
 * A `cluster.receive` span (Consumer) is opened for every routed payload; its parent is the
 * remote context extracted from `payload->trace` so the inbound work is linked to the
 * sender's trace. The span is swallow-safe — a broken tracer never disrupts routing.
 */
final class InboxRouter
{
    private int $drops = 0;

    private ?Counter $messagesReceived = null;

    private ?Counter $messagesUnroutable = null;

    public function __construct(
        private readonly InboundDelivery $delivery,
        private readonly TcpAskRegistry $askRegistry,
        private readonly ClusterMessageCodec $codec,
        private readonly OutboundSink $sink,
        private readonly TraceContextExtractor $traceExtractor,
        private readonly TraceContextInjector $traceInjector = new NoopTraceContextInjector(),
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly Tracer $tracer = new NoopTracer(),
        private readonly Meter $meter = new NoopMeter(),
    ) {}

    /**
     * @param NodeAddress $origin The peer node that delivered this payload (reply target).
     */
    public function route(NodeAddress $origin, MessagePayload $payload): void
    {
        $this->safely(fn(): mixed => $this->messagesReceivedCounter()
            ->add(1, ['nexus.message.type' => $payload->messageType]));

        $parentContext = $this->traceExtractor->extract($payload->trace);

        $span = $this->safeStartSpan('cluster.receive', SpanKind::Consumer, [
            'messaging.system' => 'nexus-tcp',
            'nexus.cluster.peer' => $origin->toPathPrefix(),
            'nexus.message.type' => $payload->messageType,
        ], $parentContext);

        try {
            $this->doRoute($origin, $payload);
        } catch (Throwable $e) {
            $this->safeRecordError($span, $e);

            throw $e;
        } finally {
            $this->safeEnd($span);
        }
    }

    public function drops(): int
    {
        return $this->drops;
    }

    private function doRoute(NodeAddress $origin, MessagePayload $payload): void
    {
        try {
            $message = $this->codec->decode($payload->messageType, $payload->body);
        } catch (MessageDeserializationException $e) {
            ++$this->drops;
            $this->logger->debug('Dropping undecodable cluster payload', [
                'error' => $e->getMessage(),
                'messageType' => $payload->messageType,
                'targetPath' => $payload->targetPath,
            ]);

            return;
        }

        if ($payload->correlationId !== null && $payload->replyPath === null) {
            $this->routeReply($payload->correlationId, $message);

            return;
        }

        // A tell has correlationId === null; a reply (correlationId set, replyPath null) was
        // handled above. So reaching here with correlationId set means an inbound ask, whose
        // replyPath is therefore guaranteed non-null.
        if ($payload->correlationId !== null && !$this->isValidAskReplyPath($origin, $payload->replyPath)) {
            // A remote peer supplies replyPath verbatim; a malformed one would throw inside the
            // delivery seam when ClusterReplyRef::path() calls ActorPath::fromString(). Reject the
            // ask here (drop, never nack) rather than let a hostile/buggy path crash routing.
            ++$this->drops;
            $this->logger->debug('Dropping cluster ask with malformed replyPath', [
                'origin' => $origin->toPathPrefix(),
                'replyPath' => $payload->replyPath,
            ]);

            return;
        }

        $replySender = $payload->correlationId !== null
            ? new ClusterReplyRef(
                $origin,
                $payload->replyPath,
                $payload->correlationId,
                $this->sink,
                $this->codec,
                $this->traceInjector,
            )
            : null;

        $outcome = $this->delivery->deliver($payload->targetPath, $message, $replySender);

        if ($outcome === DeliveryOutcome::Unroutable) {
            ++$this->drops;
            $this->safely(
                fn(): mixed => $this->messagesUnroutableCounter()->add(
                    1,
                    ['nexus.message.type' => $payload->messageType],
                ),
            );
            $this->logger->debug('Dropping unroutable cluster message', [
                'targetPath' => $payload->targetPath,
            ]);
        }
    }

    /**
     * Shape-guard an inbound ask reply path. A well-formed reply target is the temporary
     * ask-reply path the origin node minted for this ask — its own path prefix followed by
     * the `/temp/remote-ask-` marker (see {@see NodeAddress::temporaryAskReplyPath()}). We only
     * ever reply back to `$origin`, so anything not addressed under that node's own prefix, or
     * not carrying the remote-ask marker, is not a legitimate reply target and is rejected.
     * This is a shape/prefix guard, not a full ACL.
     */
    private function isValidAskReplyPath(NodeAddress $origin, ?string $replyPath): bool
    {
        if ($replyPath === null || $replyPath === '') {
            return false;
        }

        return str_starts_with($replyPath, $origin->toPathPrefix() . '/temp/remote-ask-');
    }

    private function routeReply(string $correlationId, object $message): void
    {
        if (!$this->askRegistry->resolve($correlationId, $message)) {
            ++$this->drops;
            $this->logger->debug('Dropping cluster ask reply with no pending correlation', [
                'correlationId' => $correlationId,
            ]);
        }
    }

    /**
     * @param array<string, scalar> $attributes
     */
    private function safeStartSpan(string $name, SpanKind $kind, array $attributes, ?Context $parent = null): Span
    {
        try {
            return $this->tracer->startSpan($name, $kind, $attributes, $parent);
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
            // Telemetry must never break routing.
        }
    }

    private function messagesReceivedCounter(): Counter
    {
        return $this->messagesReceived ??= $this->meter->counter(
            'nexus.cluster.messages.received',
            '{message}',
            'Cluster messages received inbound',
        );
    }

    private function messagesUnroutableCounter(): Counter
    {
        return $this->messagesUnroutable ??= $this->meter->counter(
            'nexus.cluster.messages.unroutable',
            '{message}',
            'Cluster messages dropped as unroutable',
        );
    }
}
