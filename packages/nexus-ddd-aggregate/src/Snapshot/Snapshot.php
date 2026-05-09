<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Snapshot;

use DateTimeImmutable;
use Monadial\Nexus\Ddd\Aggregate\Event\AggregateStreamId;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Persisted-snapshot wrapper. DDD-owned — replaces
 * nexus-persistence's `SnapshotEnvelope`.
 *
 * Carries the aggregate's serialized state at a specific sequence
 * number. The state is `object` (already typed via Valinor at the
 * read seam) — never raw array — matching the typed-event-object
 * convention in this package.
 *
 *   - $stateType: canonical FQCN of the state object
 *   - $stateVersion: aggregate's `stateVersion()` at the time of
 *     snapshot. Used for incompatibility detection on load.
 */
final readonly class Snapshot
{
    /**
     * @param non-empty-string $stateType
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public AggregateStreamId $streamId,
        public int $sequenceNr,
        public object $state,
        public string $stateType,
        public int $stateVersion,
        public DateTimeImmutable $occurredAt,
        public array $metadata = [],
    ) {}
}
