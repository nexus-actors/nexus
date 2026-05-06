<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Aggregate;

use Monadial\Nexus\Ddd\Core\Aggregate\Internal\ApplyDispatcher;
use Monadial\Nexus\Ddd\Core\Entity\Entity;
use Monadial\Nexus\Ddd\Core\Identity\Identifier;

/**
 * @psalm-api
 *
 * Base class for all aggregates. Subclasses must implement id() and provide
 * applyXxx() methods for every event recorded via recordThat().
 *
 * recordThat() invokes the corresponding applyXxx() synchronously to mutate
 * state, then appends the event to the recorded buffer. pullRecordedEvents()
 * returns and clears the buffer (called by the repository at persist time).
 *
 * AggregateRoot is `Entity` only — NOT `EventSourceable`. Event-sourcing
 * semantics (replaying events to rebuild state) are specific to
 * EventSourcedAggregateRoot. StatefulAggregateRoot records events for the
 * EventBus but doesn't replay — its persistence is state-based, not
 * event-based.
 *
 * Aggregates are NOT readonly — they have mutable internal state ($version,
 * $recordedEvents). Concrete subclasses are typically `final class` (not
 * readonly). Properties that should be immutable (e.g., the id) use the
 * property-level `readonly` modifier.
 */
abstract class AggregateRoot implements Entity
{
    private static ?ApplyDispatcher $dispatcher = null;

    /** @var array<int, object> */
    private array $recordedEvents = [];

    protected int $version = 0;

    protected function __construct(protected readonly Identifier $id) {}

    #[\Override]
    abstract public function id(): Identifier;

    final public function version(): int
    {
        return $this->version;
    }

    public function stateVersion(): int
    {
        return 1;
    }

    final protected function recordThat(object $event): void
    {
        self::dispatcher()->dispatch($this, $event);
        $this->recordedEvents[] = $event;
        $this->version++;
    }

    /** @return array<int, object> */
    final public function pullRecordedEvents(): array
    {
        $events = $this->recordedEvents;
        $this->recordedEvents = [];

        return $events;
    }

    #[\Override]
    final public function equals(Entity $other): bool
    {
        return $other instanceof static && $other->id->equals($this->id);
    }

    protected static function dispatcher(): ApplyDispatcher
    {
        return self::$dispatcher ??= new ApplyDispatcher();
    }
}
