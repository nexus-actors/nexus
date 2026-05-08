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

    /**
     * @param Option<string> $traceState
     */
    #[\NoDiscard('withTrace() returns a new instance — the original is unchanged')]
    public function withTrace(string $traceParent, Option $traceState): self
    {
        return new self(
            id: $this->id,
            occurredAt: $this->occurredAt,
            causationId: $this->causationId,
            correlationId: $this->correlationId,
            conversationId: $this->conversationId,
            schemaVersion: $this->schemaVersion,
            traceParent: Option::some($traceParent),
            traceState: $traceState,
            expiresAt: $this->expiresAt,
            vectorClock: $this->vectorClock,
        );
    }

    #[\NoDiscard('withExpiresAt() returns a new instance — the original is unchanged')]
    public function withExpiresAt(DateTimeImmutable $expiresAt): self
    {
        return new self(
            id: $this->id,
            occurredAt: $this->occurredAt,
            causationId: $this->causationId,
            correlationId: $this->correlationId,
            conversationId: $this->conversationId,
            schemaVersion: $this->schemaVersion,
            traceParent: $this->traceParent,
            traceState: $this->traceState,
            expiresAt: Option::some($expiresAt),
            vectorClock: $this->vectorClock,
        );
    }

    #[\NoDiscard('withVectorClock() returns a new instance — the original is unchanged')]
    public function withVectorClock(VectorClock $vectorClock): self
    {
        return new self(
            id: $this->id,
            occurredAt: $this->occurredAt,
            causationId: $this->causationId,
            correlationId: $this->correlationId,
            conversationId: $this->conversationId,
            schemaVersion: $this->schemaVersion,
            traceParent: $this->traceParent,
            traceState: $this->traceState,
            expiresAt: $this->expiresAt,
            vectorClock: Option::some($vectorClock),
        );
    }

    #[\NoDiscard('withSchemaVersion() returns a new instance — the original is unchanged')]
    public function withSchemaVersion(int $schemaVersion): self
    {
        return new self(
            id: $this->id,
            occurredAt: $this->occurredAt,
            causationId: $this->causationId,
            correlationId: $this->correlationId,
            conversationId: $this->conversationId,
            schemaVersion: $schemaVersion,
            traceParent: $this->traceParent,
            traceState: $this->traceState,
            expiresAt: $this->expiresAt,
            vectorClock: $this->vectorClock,
        );
    }

    /**
     * Derive metadata for a message *caused by* this one. The current
     * message becomes the new message's causation; correlation and
     * conversation propagate (initialized to the original id if absent —
     * the very first message in a chain is its own correlation root);
     * trace context, vector clock, schema version flow forward unchanged.
     * `expiresAt` does NOT propagate — TTL is per-message, not per-chain.
     */
    #[\NoDiscard('the derived metadata is the entire point of this call')]
    public function forCausedMessage(MessageId $newId, DateTimeImmutable $now): self
    {
        return new self(
            id: $newId,
            occurredAt: $now,
            causationId: Option::some($this->id),
            correlationId: $this->correlationId->orElse(fn() => Option::some($this->id)),
            conversationId: $this->conversationId->orElse(fn() => Option::some($this->id)),
            schemaVersion: $this->schemaVersion,
            traceParent: $this->traceParent,
            traceState: $this->traceState,
            expiresAt: Option::none(),
            vectorClock: $this->vectorClock,
        );
    }
}
