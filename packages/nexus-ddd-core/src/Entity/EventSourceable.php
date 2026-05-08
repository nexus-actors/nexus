<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Entity;

use Monadial\Nexus\Ddd\Core\Identity\Identifiable;

/**
 * @psalm-api
 *
 * @template TEvent of DomainEvent
 *
 * Anything the framework persists by replaying its event stream implements
 * this. Implementations: `EventSourcedAggregateRoot` (and downstream
 * `AbstractProcessManager` once `nexus-ddd-aggregate` lands).
 *
 * State-stored aggregates do NOT implement this — they emit DomainEvents
 * to the bus but their state lives in a row, not a stream. See
 * `EventSourcedAggregateRoot` vs. `StatefulAggregateRoot`.
 *
 * Framework hooks (`replay`, `pullRecordedEvents`) are protected on
 * `EventSourcedAggregateRoot` — repositories reach them via
 * `EventSourcedAggregateRootAccessor`. This interface exposes only the
 * read-only surface that event-store infrastructure legitimately queries.
 */
interface EventSourceable extends Identifiable
{
    public function version(): int;
}
