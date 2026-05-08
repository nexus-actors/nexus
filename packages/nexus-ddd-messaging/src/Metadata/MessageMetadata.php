<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Metadata;

use DateTimeImmutable;
use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Messaging\Clock\VectorClock;
use Monadial\Nexus\Ddd\Messaging\Identity\MessageId;
use Psr\Clock\ClockInterface;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Required metadata on every Envelope. The fields are non-negotiable
 * because they're load-bearing for audit trails (causation), tracing
 * (correlation/conversation/W3C trace context), idempotency (id), schema
 * evolution (schemaVersion), and observability (W3C trace context).
 *
 * Anything *not* in this list lives in a Stamp.
 */
final readonly class MessageMetadata
{
    /**
     * @param Option<MessageId> $causationId
     * @param Option<MessageId> $correlationId
     * @param Option<MessageId> $conversationId
     * @param Option<string> $traceParent
     * @param Option<string> $traceState
     * @param Option<DateTimeImmutable> $expiresAt
     * @param Option<VectorClock> $vectorClock
     */
    public function __construct(
        public MessageId $id,
        public DateTimeImmutable $occurredAt,
        public Option $causationId,
        public Option $correlationId,
        public Option $conversationId,
        public int $schemaVersion,
        public Option $traceParent,
        public Option $traceState,
        public Option $expiresAt,
        public Option $vectorClock,
    ) {}

    /**
     * Application-boundary factory: synthesize a root MessageMetadata for
     * the first message in a chain (HTTP controller, CLI, scheduled job).
     */
    #[\NoDiscard('the constructed metadata is the entire point of this call')]
    public static function root(ClockInterface $clock): self
    {
        return new self(
            id: MessageId::generate(),
            occurredAt: $clock->now(),
            causationId: Option::none(),
            correlationId: Option::none(),
            conversationId: Option::none(),
            schemaVersion: 1,
            traceParent: Option::none(),
            traceState: Option::none(),
            expiresAt: Option::none(),
            vectorClock: Option::none(),
        );
    }
}
