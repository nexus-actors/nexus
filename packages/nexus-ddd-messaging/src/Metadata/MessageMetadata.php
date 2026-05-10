<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Metadata;

use DateTimeImmutable;
use Fp\Functional\Option\Option;
use Monadial\Duration\FiniteDuration;
use Monadial\Nexus\Ddd\Messaging\Clock\VectorClock;
use Monadial\Nexus\Ddd\Messaging\Clock\VectorClockOrdering;
use Monadial\Nexus\Ddd\Messaging\Header\Headers;
use Monadial\Nexus\Ddd\Messaging\Identity\MessageId;
use NoDiscard;
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
 * `vectorClock` is opt-in: single-process apps leave it as `Option::none()`.
 * Distributed bus implementations tick and merge the clock at transport
 * seams. Use `withVectorClock()` to attach a clock; the predicates
 * (`happensBefore`, `compareCausalityWith`, …) return false / None when
 * either side lacks a clock.
 *
 * Anything not in this list lives in a Stamp.
 *
 * Builder methods use PHP 8.5 `clone($this, [...])` clone-with syntax: each
 * `with*` returns a fresh instance with the named properties updated and
 * the rest carried over verbatim. No need to spell out every field per
 * builder.
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
        public Headers $headers = new Headers([]),
    ) {}

    /**
     * Application-boundary factory: synthesize a root MessageMetadata for
     * the first message in a chain (HTTP controller, CLI, scheduled job).
     * The vector clock starts absent; distributed bus implementations attach
     * one via `withVectorClock()` at the send seam if needed.
     */
    #[NoDiscard('the constructed metadata is the entire point of this call')]
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
    #[NoDiscard('withTrace() returns a new instance — the original is unchanged')]
    public function withTrace(string $traceParent, Option $traceState): self
    {
        return clone($this, [
            'traceParent' => Option::some($traceParent),
            'traceState' => $traceState,
        ]);
    }

    #[NoDiscard('withExpiresAt() returns a new instance — the original is unchanged')]
    public function withExpiresAt(DateTimeImmutable $expiresAt): self
    {
        return clone($this, ['expiresAt' => Option::some($expiresAt)]);
    }

    /**
     * Override the vector clock outright. Useful when receiving a message
     * over the wire — the receiver merges the incoming clock with its own
     * known clock and re-stamps before forwarding.
     */
    #[NoDiscard('withVectorClock() returns a new instance — the original is unchanged')]
    public function withVectorClock(VectorClock $vectorClock): self
    {
        return clone($this, ['vectorClock' => Option::some($vectorClock)]);
    }

    #[NoDiscard('withSchemaVersion() returns a new instance — the original is unchanged')]
    public function withSchemaVersion(int $schemaVersion): self
    {
        return clone($this, ['schemaVersion' => $schemaVersion]);
    }

    /**
     * Replace the cross-cutting header bag outright. Use `Headers::merge`
     * upstream if appending to the existing bag is the goal.
     */
    #[NoDiscard('withHeaders() returns a new instance — the original is unchanged')]
    public function withHeaders(Headers $headers): self
    {
        return clone($this, ['headers' => $headers]);
    }

    /**
     * Derive metadata for a message *caused by* this one. The current
     * message becomes the new message's causation; correlation and
     * conversation propagate (initialized to the original id if absent —
     * the very first message in a chain is its own correlation root);
     * trace context, schema version, and vector clock flow forward
     * unchanged. `expiresAt` does NOT propagate — TTL is per-message,
     * not per-chain.
     */
    #[NoDiscard('the derived metadata is the entire point of this call')]
    public function forCausedMessage(MessageId $newId, DateTimeImmutable $now): self
    {
        return clone($this, [
            'causationId' => Option::some($this->id),
            'conversationId' => $this->conversationId->orElse(fn() => Option::some($this->id)),
            'correlationId' => $this->correlationId->orElse(fn() => Option::some($this->id)),
            'expiresAt' => Option::none(),
            'id' => $newId,
            'occurredAt' => $now,
        ]);
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

    public function hasVectorClock(): bool
    {
        return $this->vectorClock->isSome();
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
        return $this->compareCausalityWith($other)
            ->map(static fn(VectorClockOrdering $o): bool => $o === VectorClockOrdering::HappensBefore)
            ->getOrElse(false);
    }

    public function happensAfter(self $other): bool
    {
        return $this->compareCausalityWith($other)
            ->map(static fn(VectorClockOrdering $o): bool => $o === VectorClockOrdering::HappensAfter)
            ->getOrElse(false);
    }

    public function isConcurrentWith(self $other): bool
    {
        return $this->compareCausalityWith($other)
            ->map(static fn(VectorClockOrdering $o): bool => $o === VectorClockOrdering::Concurrent)
            ->getOrElse(false);
    }

    /** @return Option<VectorClockOrdering> None when either side lacks a clock. */
    #[NoDiscard('compareCausalityWith returns an Option — ignoring it loses the result')]
    public function compareCausalityWith(self $other): Option
    {
        return $this->vectorClock->flatMap(
            static fn(VectorClock $a): Option => $other->vectorClock->map(
                static fn(VectorClock $b): VectorClockOrdering => $a->compareTo($b),
            ),
        );
    }

    /** @psalm-mutation-free */
    private static function durationBetween(DateTimeImmutable $earlier, DateTimeImmutable $later): FiniteDuration
    {
        $secondsDiff = $later->getTimestamp() - $earlier->getTimestamp();
        $microsDiff = (int) $later->format('u') - (int) $earlier->format('u');

        return FiniteDuration::fromNanos(($secondsDiff * 1_000_000_000) + ($microsDiff * 1_000));
    }
}
