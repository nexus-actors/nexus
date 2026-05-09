<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Event;

use DateTimeImmutable;
use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Persisted-event wrapper. The DDD-owned envelope, owned by this
 * package so it can be extracted without dragging the actor-system
 * persistence layer along. Fields:
 *   - $streamId: which aggregate's stream this event belongs to
 *   - $sequenceNr: monotonic per-stream sequence (1-indexed)
 *   - $event: the typed DomainEvent (already deserialized via Valinor at the read seam)
 *   - $eventType: canonical event class FQCN (used by serializers)
 *   - $occurredAt: when the event was persisted (clock-derived, not domain-time)
 *   - $metadata: free-form bag for transport-level metadata
 */
final readonly class StoredEvent
{
    /**
     * @param non-empty-string $eventType
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public AggregateStreamId $streamId,
        public int $sequenceNr,
        public DomainEvent $event,
        public string $eventType,
        public DateTimeImmutable $occurredAt,
        public array $metadata = [],
    ) {}
}
