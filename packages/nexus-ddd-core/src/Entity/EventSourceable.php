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
 */
interface EventSourceable extends Identifiable
{
    /** @return array<int, TEvent> */
    public function pullRecordedEvents(): array;

    /** @param iterable<int, TEvent> $events */
    public function replay(iterable $events): void;

    public function version(): int;
}
