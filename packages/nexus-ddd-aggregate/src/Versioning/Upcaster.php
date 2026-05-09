<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Versioning;

use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;

/**
 * @psalm-api
 *
 * @template TIn of DomainEvent
 * @template TOut of DomainEvent
 *
 * Pure transformation `(eventV_n, context) → eventV_n+1` for ONE event
 * version transition. Implementations work with typed `DomainEvent`
 * objects on both input and output — never raw payload arrays. Each
 * historical version has its own concrete event class (e.g.,
 * `OrderPlacedV1`, `OrderPlacedV2`, …); the upcaster's job is the
 * class-to-class transform, with field-level renames or computations
 * happening through the constructor of the target class.
 *
 * Implementations declare which event-name chain they belong to
 * (`eventName()` returns the stable name from the `#[Event]` attribute,
 * shared by every version) and which version pair they bridge
 * (`fromVersion` → `toVersion`, typically `n` → `n+1`).
 *
 * MUST be a pure function:
 *   - no clock reads, no RNG, no logger, no container access
 *   - no aggregate state access (the upcaster operates on the event alone)
 *   - same restriction enforced by `ReplaySafeApplyRule` Psalm rule that
 *     governs `EventSourcedAggregateRoot::apply()`
 *
 * The persisted event store JSON is mapped back to the typed v_n class
 * by Valinor (or equivalent) BEFORE the upcaster sees it, so the
 * upcaster never touches arrays.
 */
interface Upcaster
{
    public function eventName(): string;

    public function fromVersion(): int;

    public function toVersion(): int;

    /**
     * @param TIn $event
     * @return TOut
     */
    public function upcast(DomainEvent $event, UpcastContext $context): DomainEvent;
}
