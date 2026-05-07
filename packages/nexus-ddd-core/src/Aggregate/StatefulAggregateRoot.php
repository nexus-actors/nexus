<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Aggregate;

use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;

/**
 * @psalm-api
 *
 * Base for STATE-STORED aggregates — those whose state lives in a
 * Doctrine row (or similar) and is NOT reconstructed by replaying
 * events. Concrete subclasses extend this to declare intent: *"my
 * persistence is a snapshot, not a stream."*
 *
 * **Key contract differences from `EventSourcedAggregateRoot`:**
 *
 * - State mutation happens **directly inside command methods** — not
 *   through `applyXxx` methods. There is no apply-dispatch convention
 *   here; do NOT define `applyXxx` methods on a stateful aggregate.
 * - `recordThat()` only buffers the event and bumps version. Recorded
 *   events drain via `pullRecordedEvents()` (typically called by the
 *   repository at persist time); how/where they're then dispatched is
 *   the messaging layer's concern, not this package's.
 * - This class deliberately does NOT implement `EventSourceable`.
 *   Type-discrimination of "is this aggregate event-sourced?" is
 *   answered by `instanceof EventSourceable`; the named subclass
 *   `StatefulAggregateRoot` is offered as the symmetric, intent-
 *   revealing counterpart.
 *
 * Example:
 *
 *     final class Customer extends StatefulAggregateRoot {
 *         public string $name;
 *
 *         public static function register(CustomerId $id, string $name): self {
 *             $c = new self($id);
 *             $c->name = $name;
 *             $c->recordThat(new CustomerRegistered($id, $name));
 *             return $c;
 *         }
 *
 *         public function rename(string $newName): void {
 *             $this->check($newName !== '', new NameMustBeNonEmpty());
 *             $this->name = $newName;   // direct mutation
 *             $this->recordThat(new CustomerRenamed($this->id, $newName));
 *         }
 *
 *         #[\Override]
 *         public function id(): Identifier {
 *             return $this->id;
 *         }
 *     }
 *
 * @template TEvent of DomainEvent
 * @extends AggregateRoot<TEvent>
 */
abstract class StatefulAggregateRoot extends AggregateRoot {}
