<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Aggregate;

use Monadial\Nexus\Ddd\Core\Aggregate\Internal\ApplyDispatcher;
use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\Core\Entity\Entity;
use Monadial\Nexus\Ddd\Core\Identity\Identifier;
use Monadial\Nexus\Ddd\Core\Exception\DomainException;

/**
 * @psalm-api
 *
 * Base for all aggregates — the consistency boundary that protects domain
 * invariants. Subclasses must implement `id()` and use `recordThat()` to
 * emit `DomainEvent`s when domain rules are exercised.
 *
 * **State-stored vs event-sourced**: extend this class directly for a
 * state-stored aggregate (the kind whose state lives in a Doctrine row).
 * Such aggregates emit events for the bus but do not replay them — state is
 * mutated directly inside command handlers, NOT via `applyXxx()`.
 *
 * For an event-sourced aggregate, extend `EventSourcedAggregateRoot`
 * instead — it adds `replay()` and routes `recordThat()` through the
 * applyXxx convention so state stays in sync with the recorded stream.
 *
 * Aggregates are NOT readonly — they have mutable internal state ($version,
 * $recordedEvents). Concrete subclasses are typically `final class` (not
 * readonly). The id is constructor-promoted with the property-level
 * `readonly` modifier.
 *
 * Use `check()` inside command methods to assert invariants — it throws a
 * `DomainException` subclass (or a plain message) when violated, which is
 * the right exception family for business rule failures.
 */
abstract class AggregateRoot implements Entity
{
    /** @var array<int, DomainEvent> */
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

    /**
     * Record that a domain event happened. State-stored aggregates simply
     * append; event-sourced aggregates override this to also dispatch the
     * event through the applyXxx convention.
     */
    protected function recordThat(DomainEvent $event): void
    {
        $this->recordedEvents[] = $event;
        $this->version++;
    }

    /** @return array<int, DomainEvent> */
    #[\NoDiscard('pullRecordedEvents() drains the buffer — discarding the return loses every recorded event')]
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

    /**
     * Invariant guard. Use inside command methods to assert a domain rule
     * holds before recording the event. Pass an exception instance for a
     * typed failure (preferred) or a plain string for ad-hoc rules.
     *
     * @throws DomainException
     */
    final protected function check(bool $condition, DomainException|string $rule): void
    {
        if ($condition) {
            return;
        }

        throw $rule instanceof DomainException
            ? $rule
            : new class ($rule) extends DomainException {};
    }
}
