<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Aggregate;

use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\Core\Entity\Entity;
use Monadial\Nexus\Ddd\Core\Exception\DomainException;
use Monadial\Nexus\Ddd\Core\Identity\Identifier;
use NoDiscard;
use Override;

/**
 * @psalm-api
 *
 * @template TId of Identifier
 * @template TEvent of DomainEvent
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
 *      events. `recordThat()` routes through an abstract `apply()` method
 *      so state stays in lock-step with the recorded stream.
 *
 * **Constrain identity AND event family.** Concrete aggregates declare
 * both their identifier type and the closed set of events they may emit
 * via `@extends ...<TheirIdType, TheirEventInterface>`. Psalm then
 * refuses `new SomeAggregate($wrongId)` and `recordThat($strayEvent)` —
 * the closest PHP gets to a sealed-aggregate definition.
 *
 *     final readonly class OrderId extends UlidValue {}
 *     interface OrderEvent extends DomainEvent {}
 *     final readonly class OrderPlaced implements OrderEvent { ... }
 *
 *     /** @extends EventSourcedAggregateRoot<OrderId, OrderEvent> *\/
 *     final class Order extends EventSourcedAggregateRoot {
 *         public function id(): OrderId { return $this->id; }
 *     }
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
    protected int $version = 0;

    /** @var array<int, TEvent> */
    protected array $recordedEvents = [];

    final public function version(): int
    {
        return $this->version;
    }

    #[Override]
    final public function equals(Entity $other): bool
    {
        return $other instanceof static && $other->id->equals($this->id);
    }

    /** @return TId */
    #[Override]
    abstract public function id(): Identifier;

    /** @return array<int, TEvent> */
    #[NoDiscard('pullRecordedEvents() drains the buffer — discarding the return loses every recorded event')]
    final protected function pullRecordedEvents(): array
    {
        $events = $this->recordedEvents;
        $this->recordedEvents = [];

        return $events;
    }

    /**
     * Rehydrate the aggregate version from a snapshot. Called by the
     * snapshot store (or a `#[SnapshotConstructor]`-marked factory) after
     * constructing the aggregate from its snapshotted state — sets the
     * version to the stream revision at which the snapshot was taken.
     *
     * After this, `replay()` may be called with events written *after*
     * that revision; each replayed event continues bumping version.
     *
     * Aggregate revision and stream position are the same number, set in
     * exactly two places: `recordThat()`/`replay()` increment by one;
     * `rehydrateVersion()` sets to a known absolute. There is no third
     * mutation path — do not bypass these.
     *
     * @internal Framework wiring entry point. Domain code must NOT call
     *           this — bypassing it will desync the aggregate from its
     *           stream.
     */
    final protected function rehydrateVersion(int $revision): void
    {
        $this->version = $revision;
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

    /** @param TId $id */
    protected function __construct(protected readonly Identifier $id) {}

    /**
     * Record that a domain event happened. State-stored aggregates simply
     * append; event-sourced aggregates override this to also dispatch the
     * event through the applyXxx convention.
     *
     * @param TEvent $event
     */
    protected function recordThat(DomainEvent $event): void
    {
        $this->recordedEvents[] = $event;
        $this->version++;
    }
}
