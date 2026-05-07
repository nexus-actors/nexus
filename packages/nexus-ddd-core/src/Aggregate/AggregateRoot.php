<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Aggregate;

use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\Core\Entity\Entity;
use Monadial\Nexus\Ddd\Core\Exception\DomainException;
use Monadial\Nexus\Ddd\Core\Identity\Identifier;

/**
 * @psalm-api
 *
 * Base for all aggregates — the consistency boundary that protects domain
 * invariants. Subclasses must implement `id()` and use `recordThat()` to
 * emit `DomainEvent`s when domain rules are exercised.
 *
 * **Do NOT extend this class directly.** Choose one of the two intent-
 * revealing subclasses:
 *
 *   - `StatefulAggregateRoot` — state lives in a Doctrine row (or
 *      similar). State is mutated directly inside command methods.
 *      Events are emitted to the bus but not used to rebuild state.
 *   - `EventSourcedAggregateRoot` — state is reconstructed by replaying
 *      events. `recordThat()` routes through an `applyXxx` convention so
 *      state stays in lock-step with the recorded stream.
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
     * holds before recording the event.
     *
     * **Prefer the typed branch.** Pass a concrete `DomainException`
     * subclass — e.g., `OrderAlreadyShipped`, `InsufficientFunds` — so the
     * rule has a name in the ubiquitous language and can be caught
     * specifically by interested handlers.
     *
     * The string branch is for **prototyping only**. It throws an
     * anonymous `DomainException` subclass: catchable as `DomainException`,
     * but not by a specific type because each call site mints a fresh
     * anonymous class. Once the rule has a name in the team's vocabulary,
     * promote it to a typed `DomainException` subclass and migrate the
     * call site.
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
