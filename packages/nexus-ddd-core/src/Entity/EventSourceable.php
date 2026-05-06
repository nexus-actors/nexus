<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Entity;

use Monadial\Nexus\Ddd\Core\Identity\Identifiable;

/**
 * @psalm-api
 *
 * Anything the framework persists via EventSourcingStrategy implements this.
 * AggregateRoot and AbstractProcessManager both implement EventSourceable.
 */
interface EventSourceable extends Identifiable
{
    /** @return array<int, object> */
    public function pullRecordedEvents(): array;

    /** @param iterable<int, object> $events */
    public function replay(iterable $events): void;

    public function version(): int;

    public function stateVersion(): int;
}
