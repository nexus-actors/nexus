<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Entity;

use Monadial\Nexus\Ddd\Core\Identity\Identifiable;

/**
 * @psalm-api
 *
 * Anything the framework persists by replaying its event stream implements
 * this. Implementations: `EventSourcedAggregateRoot` (and downstream
 * `AbstractProcessManager` once `nexus-ddd-aggregate` lands).
 *
 * State-stored aggregates do NOT implement this — they emit DomainEvents
 * to the bus but their state lives in a row, not a stream. See
 * `EventSourcedAggregateRoot` vs. extending `AggregateRoot` directly.
 */
interface EventSourceable extends Identifiable
{
    /** @return array<int, DomainEvent> */
    public function pullRecordedEvents(): array;

    /** @param iterable<int, DomainEvent> $events */
    public function replay(iterable $events): void;

    public function version(): int;

    public function stateVersion(): int;
}
