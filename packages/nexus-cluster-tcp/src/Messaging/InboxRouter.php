<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Messaging;

use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\Tcp\Payload\MessagePayload;
use Monadial\Nexus\Serialization\Exception\MessageDeserializationException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

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
 */
final class InboxRouter
{
    private int $drops = 0;

    public function __construct(
        private readonly InboundDelivery $delivery,
        private readonly TcpAskRegistry $askRegistry,
        private readonly ClusterMessageCodec $codec,
        private readonly OutboundSink $sink,
        private readonly TraceContextExtractor $traceExtractor,
        private readonly TraceContextInjector $traceInjector = new NoopTraceContextInjector(),
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * @param NodeAddress $origin The peer node that delivered this payload (reply target).
     */
    public function route(NodeAddress $origin, MessagePayload $payload): void
    {
        $this->traceExtractor->extract($payload->trace);

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
            $this->logger->debug('Dropping unroutable cluster message', [
                'targetPath' => $payload->targetPath,
            ]);
        }
    }

    public function drops(): int
    {
        return $this->drops;
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
}
