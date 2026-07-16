<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Messaging;

use Closure;
use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\Tcp\Frame;
use Monadial\Nexus\Cluster\Tcp\FrameType;
use Monadial\Nexus\Cluster\Tcp\Payload\MessagePayloadCodec;
use Monadial\Nexus\Observability\Metric\Counter;
use Monadial\Nexus\Observability\Metric\Histogram;
use Monadial\Nexus\Observability\Metric\Meter;
use Monadial\Nexus\Observability\Metric\NoopMeter;
use Monadial\Nexus\Serialization\Exception\MessageDeserializationException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function strlen;

/**
 * @psalm-api
 *
 * Decodes inbound cluster frames and routes {@see FrameType::Message} frames to the
 * {@see InboxRouter}. Each instance is associated with one peer (the `$origin` address
 * is fixed at construction).
 *
 * {@see FrameType::Message} frames are decoded to a
 * {@see \Monadial\Nexus\Cluster\Tcp\Payload\MessagePayload} VO via the hand-rolled
 * {@see MessagePayloadCodec} (the envelope is the per-message hot path; the generic
 * Valinor-backed serializer stays on the low-frequency handshake/gossip frames) and handed
 * to {@see InboxRouter::route()} with the peer's node address as the origin. All other
 * frame types are forwarded to the optional `$fallback` handler or silently ignored — they
 * are the responsibility of the membership/handshake layer.
 *
 * Usage:
 *   $ingress = new FrameIngress($router, $peerAddress, $payloadCodec);
 *   $peerLink->onFrame(fn(Frame $frame) => $ingress->ingest($frame));
 */
final class FrameIngress
{
    /** @var Closure(Frame): void|null */
    private readonly ?Closure $fallback;

    private ?Counter $framesReceived = null;

    private ?Counter $framesDecodeFailed = null;

    private ?Histogram $bytesReceived = null;

    /**
     * @param (Closure(Frame): void)|null $fallback Optional handler for non-Message frames.
     */
    public function __construct(
        private readonly InboxRouter $router,
        private readonly NodeAddress $origin,
        private readonly MessagePayloadCodec $payloadCodec,
        ?callable $fallback = null,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly Meter $meter = new NoopMeter(),
    ) {
        /**
         *                                           callable syntax is safe here; Psalm cannot infer
         *                                           the specific Closure(Frame): void signature.
         */
        $this->fallback = $fallback !== null
            ? $fallback(...)
            : null;
    }

    /**
     * Process one inbound frame. {@see FrameType::Message} frames are decoded and routed;
     * other frame types are passed to the fallback handler (if any) or ignored.
     */
    public function ingest(Frame $frame): void
    {
        $this->safely(fn(): mixed => $this->framesReceivedCounter()->add(1, ['frame.type' => $frame->type->name]));
        $this->safely(fn(): mixed => $this->bytesReceivedHistogram()->record(strlen($frame->payload)));

        if ($frame->type !== FrameType::Message) {
            if ($this->fallback !== null) {
                ($this->fallback)($frame);
            }

            return;
        }

        try {
            $payload = $this->payloadCodec->unpack($frame->payload);
        } catch (MessageDeserializationException $e) {
            // Mirror the control-frame decode observability (ClusterNode::recordDecodeFailure): an
            // undecodable user-message frame is otherwise silent, leaving an operator with no signal
            // while a corrupt or version-skewed peer's messages quietly vanish.
            $this->safely(
                fn(): mixed => $this->framesDecodeFailedCounter()->add(1, ['frame.type' => $frame->type->name]),
            );
            $this->logger->warning('FrameIngress: dropping undecodable Message frame from peer', [
                'error' => $e->getMessage(),
                'peer' => $this->origin->toPathPrefix(),
            ]);

            return;
        }

        $this->router->route($this->origin, $payload);
    }

    /**
     * @param callable(): mixed $fn
     */
    private function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable) {
            // Telemetry must never break frame processing.
        }
    }

    private function framesReceivedCounter(): Counter
    {
        return $this->framesReceived ??= $this->meter->counter(
            'nexus.cluster.frames.received',
            '{frame}',
            'Cluster frames received from remote peers',
        );
    }

    private function framesDecodeFailedCounter(): Counter
    {
        return $this->framesDecodeFailed ??= $this->meter->counter(
            'nexus.cluster.frames.decode_failed',
            '{frame}',
            'Inbound frames dropped because they could not be decoded',
        );
    }

    private function bytesReceivedHistogram(): Histogram
    {
        return $this->bytesReceived ??= $this->meter->histogram(
            'nexus.cluster.bytes.received',
            'By',
            'Bytes received in inbound cluster frames',
        );
    }
}
