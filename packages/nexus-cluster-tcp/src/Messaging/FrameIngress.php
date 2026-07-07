<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Messaging;

use Closure;
use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\Tcp\Frame;
use Monadial\Nexus\Cluster\Tcp\FrameType;
use Monadial\Nexus\Cluster\Tcp\Payload\MessagePayload;
use Monadial\Nexus\Observability\Metric\Counter;
use Monadial\Nexus\Observability\Metric\Histogram;
use Monadial\Nexus\Observability\Metric\Meter;
use Monadial\Nexus\Observability\Metric\NoopMeter;
use Monadial\Nexus\Serialization\Exception\MessageDeserializationException;
use Monadial\Nexus\Serialization\MessageSerializer;
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
 * {@see FrameType::Message} frames are deserialized to a {@see MessagePayload} VO via the
 * injected serializer and handed to {@see InboxRouter::route()} with the peer's node address
 * as the origin. All other frame types are forwarded to the optional `$fallback` handler or
 * silently ignored — they are the responsibility of the membership/handshake layer.
 *
 * Usage:
 *   $ingress = new FrameIngress($router, $peerAddress, $serializer);
 *   $peerLink->onFrame(fn(Frame $frame) => $ingress->ingest($frame));
 */
final class FrameIngress
{
    /**
     * The well-known cluster type name for MessagePayload, set via its #[MessageType] attribute.
     *
     * @see \Monadial\Nexus\Cluster\Tcp\Payload\MessagePayload
     */
    private const string MESSAGE_PAYLOAD_TYPE = 'cluster.message';

    /** @var Closure(Frame): void|null */
    private readonly ?Closure $fallback;

    private ?Counter $framesReceived = null;

    private ?Histogram $bytesReceived = null;

    /**
     * @param (Closure(Frame): void)|null $fallback Optional handler for non-Message frames.
     */
    public function __construct(
        private readonly InboxRouter $router,
        private readonly NodeAddress $origin,
        private readonly MessageSerializer $payloadSerializer,
        ?callable $fallback = null,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly Meter $meter = new NoopMeter(),
    ) {
        /**
         * @psalm-suppress MixedPropertyTypeCoercion The callable-to-Closure coercion via first-class
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
            $payload = $this->payloadSerializer->deserialize($frame->payload, self::MESSAGE_PAYLOAD_TYPE);
        } catch (MessageDeserializationException $e) {
            $this->logger->warning('FrameIngress: dropping undecodable Message frame from peer', [
                'error' => $e->getMessage(),
                'peer' => $this->origin->toPathPrefix(),
            ]);

            return;
        }

        if (!$payload instanceof MessagePayload) {
            $this->logger->warning('FrameIngress: deserializer returned unexpected type for cluster.message', [
                'peer' => $this->origin->toPathPrefix(),
                'type' => $payload::class,
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

    private function bytesReceivedHistogram(): Histogram
    {
        return $this->bytesReceived ??= $this->meter->histogram(
            'nexus.cluster.bytes.received',
            'By',
            'Bytes received in inbound cluster frames',
        );
    }
}
