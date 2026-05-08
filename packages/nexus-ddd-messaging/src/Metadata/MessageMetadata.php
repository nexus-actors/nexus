<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Metadata;

use DateTimeImmutable;
use Fp\Functional\Option\Option;
use Monadial\Duration\FiniteDuration;
use Monadial\Nexus\Ddd\Messaging\Clock\VectorClock;
use Monadial\Nexus\Ddd\Messaging\Clock\VectorClockOrdering;
use Monadial\Nexus\Ddd\Messaging\Identity\MessageId;
use Monadial\Nexus\Ddd\Messaging\Identity\NodeId;
use NoDiscard;
use Psr\Clock\ClockInterface;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Required metadata on every Envelope. The fields are non-negotiable
 * because they're load-bearing for audit trails (causation), tracing
 * (correlation/conversation/W3C trace context), idempotency (id), schema
 * evolution (schemaVersion), observability (W3C trace context), and
 * partial-order causality (vectorClock — always present, ticked on every
 * causal hop).
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
        public VectorClock $vectorClock,
    ) {}

    /**
     * Application-boundary factory: synthesize a root MessageMetadata for
     * the first message in a chain (HTTP controller, CLI, scheduled job).
     * The producing node ticks its own counter so the resulting metadata
     * carries a non-empty vector clock — every send is one logical event
     * in Lamport-Mattern terms.
     */
    #[NoDiscard('the constructed metadata is the entire point of this call')]
    public static function root(ClockInterface $clock, NodeId $nodeId): self
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
            vectorClock: VectorClock::empty()->tick($nodeId),
        );
    }

    /**
     * @param Option<string> $traceState
     */
    #[NoDiscard('withTrace() returns a new instance — the original is unchanged')]
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

    #[NoDiscard('withExpiresAt() returns a new instance — the original is unchanged')]
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

    /**
     * Override the vector clock outright. Useful when receiving a message
     * over the wire — the receiver merges the incoming clock with its own
     * known clock and re-stamps before forwarding.
     */
    #[NoDiscard('withVectorClock() returns a new instance — the original is unchanged')]
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
            vectorClock: $vectorClock,
        );
    }

    #[NoDiscard('withSchemaVersion() returns a new instance — the original is unchanged')]
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
     * trace context and schema version flow forward unchanged. The vector
     * clock propagates *and is ticked* by the producing node so the new
     * metadata strictly happens-after the parent.
     * `expiresAt` does NOT propagate — TTL is per-message, not per-chain.
     */
    #[NoDiscard('the derived metadata is the entire point of this call')]
    public function forCausedMessage(MessageId $newId, DateTimeImmutable $now, NodeId $nodeId): self
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
            vectorClock: $this->vectorClock->tick($nodeId),
        );
    }

    public function isRoot(): bool
    {
        return $this->causationId->isNone();
    }

    public function isCausedBy(MessageId $id): bool
    {
        return $this->causationId
            ->map(static fn(MessageId $c) => $c->equals($id))
            ->getOrElse(false);
    }

    public function correlatesTo(MessageId $id): bool
    {
        return $this->correlationId
            ->map(static fn(MessageId $c) => $c->equals($id))
            ->getOrElse(false);
    }

    public function isPartOfConversation(MessageId $id): bool
    {
        return $this->conversationId
            ->map(static fn(MessageId $c) => $c->equals($id))
            ->getOrElse(false);
    }

    public function hasTrace(): bool
    {
        return $this->traceParent->isSome();
    }

    public function hasExpiry(): bool
    {
        return $this->expiresAt->isSome();
    }

    public function isExpired(DateTimeImmutable $now): bool
    {
        return $this->expiresAt
            ->map(static fn(DateTimeImmutable $at) => $at <= $now)
            ->getOrElse(false);
    }

    /** @return Option<\Monadial\Duration\FiniteDuration> */
    #[NoDiscard('timeUntilExpiry returns the remaining duration; ignoring it loses the value')]
    public function timeUntilExpiry(DateTimeImmutable $now): Option
    {
        return $this->expiresAt
            ->filter(static fn(DateTimeImmutable $at) => $at > $now)
            ->map(static fn(DateTimeImmutable $at) => self::durationBetween($now, $at));
    }

    #[NoDiscard('ageAt returns the elapsed duration; ignoring it loses the value')]
    public function ageAt(DateTimeImmutable $now): FiniteDuration
    {
        return self::durationBetween($this->occurredAt, $now);
    }

    public function happensBefore(self $other): bool
    {
        return $this->vectorClock->compareTo($other->vectorClock) === VectorClockOrdering::HappensBefore;
    }

    public function happensAfter(self $other): bool
    {
        return $this->vectorClock->compareTo($other->vectorClock) === VectorClockOrdering::HappensAfter;
    }

    public function isConcurrentWith(self $other): bool
    {
        return $this->vectorClock->compareTo($other->vectorClock) === VectorClockOrdering::Concurrent;
    }

    public function compareCausalityWith(self $other): VectorClockOrdering
    {
        return $this->vectorClock->compareTo($other->vectorClock);
    }

    /** @psalm-pure */
    private static function durationBetween(DateTimeImmutable $earlier, DateTimeImmutable $later,): FiniteDuration {
        $secondsDiff = $later->getTimestamp() - $earlier->getTimestamp();
        $microsDiff = (int) $later->format('u') - (int) $earlier->format('u');

        return FiniteDuration::fromNanos(($secondsDiff * 1_000_000_000) + ($microsDiff * 1_000));
    }
}
