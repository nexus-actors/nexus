<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Messaging;

use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\Tcp\DeliveryOutcome;
use Monadial\Nexus\Cluster\Tcp\EndpointResolver;
use Monadial\Nexus\Cluster\Tcp\Frame;
use Monadial\Nexus\Cluster\Tcp\FrameType;
use Monadial\Nexus\Cluster\Tcp\MeshTransport;
use Monadial\Nexus\Cluster\Tcp\NodeEndpoint;
use Monadial\Nexus\Cluster\Tcp\Payload\MessagePayload;
use Monadial\Nexus\Cluster\Tcp\Payload\MessagePayloadCodec;
use Monadial\Nexus\Cluster\Tcp\PeerConnection;
use Monadial\Nexus\Observability\Metric\Counter;
use Monadial\Nexus\Observability\Metric\Histogram;
use Monadial\Nexus\Observability\Metric\Meter;
use Monadial\Nexus\Observability\Metric\NoopMeter;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Runtime\Runtime;
use Override;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function strlen;

/**
 * @psalm-api
 *
 * Transport-backed {@see OutboundSink} that maintains one {@see PeerConnection} per resolved
 * peer endpoint. Connections are created lazily on first send and reused thereafter.
 *
 * For each send:
 *   1. Resolve the target {@see NodeAddress} to a {@see NodeEndpoint} via the injected
 *      {@see EndpointResolver}.
 *   2. Pack the {@see MessagePayload} to bytes via the hand-rolled {@see MessagePayloadCodec}.
 *   3. Wrap the bytes in a {@see FrameType::Message} frame and enqueue it on the peer's
 *      {@see PeerConnection} (which handles buffering and reconnect automatically).
 *
 * Every send returns a {@see DeliveryOutcome} (at-most-once): an unresolvable endpoint returns
 * {@see DeliveryOutcome::Dropped} (also counted via {@see drops()}), a disconnected peer returns
 * {@see DeliveryOutcome::Buffered}, and a live link returns {@see DeliveryOutcome::Admitted}. The
 * outcome is metered so `nexus.cluster.frames.sent` counts only admitted frames.
 */
final class MeshOutboundSink implements OutboundSink
{
    /** @var array<string, PeerConnection> */
    private array $connections = [];

    private int $drops = 0;

    private ?Counter $sendBufferDropped = null;

    private ?Counter $framesSent = null;

    private ?Counter $framesBuffered = null;

    private ?Counter $framesDropped = null;

    private ?Histogram $bytesSent = null;

    public function __construct(
        private readonly EndpointResolver $resolver,
        private readonly MeshTransport $transport,
        private readonly Runtime $runtime,
        private readonly MessagePayloadCodec $payloadCodec,
        private readonly Duration $reconnectInitialBackoff,
        private readonly Duration $reconnectMaxBackoff,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly Meter $meter = new NoopMeter(),
    ) {}

    #[Override]
    public function send(NodeAddress $target, MessagePayload $payload): DeliveryOutcome
    {
        $endpoint = $this->resolver->resolve($target);

        if ($endpoint === null) {
            ++$this->drops;
            $this->safely(fn(): mixed => $this->sendBufferDroppedCounter()->add(1));
            $this->recordOutcome(DeliveryOutcome::Dropped, 'no_route');
            $this->logger->debug('MeshOutboundSink: dropping message — no endpoint registered for node', [
                'target' => $target->toPathPrefix(),
            ]);

            return DeliveryOutcome::Dropped;
        }

        $connection = $this->getOrCreate($endpoint);
        $bytes = $this->payloadCodec->pack($payload);
        $this->safely(fn(): mixed => $this->bytesSentHistogram()->record(strlen($bytes)));
        $outcome = $connection->sendFrame(new Frame(FrameType::Message, $bytes));
        $this->recordOutcome($outcome, 'peer_unavailable');

        return $outcome;
    }

    /**
     * Close all peer connections. No-op on already-closed connections.
     */
    public function close(): void
    {
        foreach ($this->connections as $connection) {
            $connection->close();
        }

        $this->connections = [];
    }

    /**
     * Number of messages dropped due to unresolvable endpoint.
     */
    public function drops(): int
    {
        return $this->drops;
    }

    /**
     * Emit the delivery-admission counter for one message, keyed by outcome so telemetry
     * never reports a dropped or merely-buffered frame as sent. `nexus.cluster.frames.sent`
     * counts ONLY admitted frames (the historical name, now truthful); buffered and dropped
     * frames land on their own counters, and a dropped frame carries the `drop.reason`.
     */
    private function recordOutcome(DeliveryOutcome $outcome, string $dropReason): void
    {
        $this->safely(function () use ($outcome, $dropReason): void {
            match ($outcome) {
                DeliveryOutcome::Admitted => $this->framesSentCounter()->add(1, ['frame.type' => 'message']),
                DeliveryOutcome::Buffered => $this->framesBufferedCounter()->add(1, ['frame.type' => 'message']),
                DeliveryOutcome::Dropped => $this->framesDroppedCounter()->add(
                    1,
                    ['drop.reason' => $dropReason, 'frame.type' => 'message'],
                ),
            };
        });
    }

    private function getOrCreate(NodeEndpoint $endpoint): PeerConnection
    {
        $key = (string) $endpoint;

        if (!isset($this->connections[$key])) {
            $this->connections[$key] = new PeerConnection(
                $endpoint,
                $this->transport,
                $this->runtime,
                $this->reconnectInitialBackoff,
                $this->reconnectMaxBackoff,
            );
        }

        return $this->connections[$key];
    }

    /**
     * @param callable(): mixed $fn
     */
    private function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable) {
            // Telemetry must never break transport.
        }
    }

    private function sendBufferDroppedCounter(): Counter
    {
        return $this->sendBufferDropped ??= $this->meter->counter(
            'nexus.cluster.send_buffer.dropped',
            '{message}',
            'Messages dropped due to unresolvable peer endpoint',
        );
    }

    private function framesSentCounter(): Counter
    {
        return $this->framesSent ??= $this->meter->counter(
            'nexus.cluster.frames.sent',
            '{frame}',
            'Cluster frames admitted to a live link (written to the socket) — not a delivery receipt',
        );
    }

    private function framesBufferedCounter(): Counter
    {
        return $this->framesBuffered ??= $this->meter->counter(
            'nexus.cluster.frames.buffered',
            '{frame}',
            'Cluster frames queued for a reconnecting peer — may still be lost if reconnect fails',
        );
    }

    private function framesDroppedCounter(): Counter
    {
        return $this->framesDropped ??= $this->meter->counter(
            'nexus.cluster.frames.dropped',
            '{frame}',
            'Cluster frames not admitted (no route, buffer full, or write failed) — the message is gone',
        );
    }

    private function bytesSentHistogram(): Histogram
    {
        return $this->bytesSent ??= $this->meter->histogram(
            'nexus.cluster.bytes.sent',
            'By',
            'Bytes sent in outbound cluster frames',
        );
    }
}
