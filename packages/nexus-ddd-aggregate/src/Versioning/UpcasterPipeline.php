<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Versioning;

use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;

/**
 * @psalm-api
 *
 * Composes a chain of `Upcaster`s into a pipeline. Operates on typed
 * `DomainEvent` objects on both sides — never raw payload arrays.
 * Persistence-layer Valinor mapping happens BEFORE the pipeline sees
 * the event, so each historical version is delivered as its own typed
 * class instance (e.g., `OrderPlacedV1` → `OrderPlacedV2`).
 *
 * Two read modes:
 *   - `upcast()` — drives to the highest registered version (used for
 *     aggregate replay, which must always reach current shape)
 *   - `upcastTo($targetVersion)` — pins the chain to a specific target
 *     (used for projection rebuilds that need to reproduce the historical
 *     payload shape they shipped against; per v6 spec §10.2)
 *
 * Both methods take the input event's logical name + its from-version
 * (read from the input class's `#[Event]` attribute by the caller) so
 * the pipeline can locate the right chain without re-introspecting.
 * The latest version is dynamic — return type is `DomainEvent`, not a
 * statically-known class.
 */
interface UpcasterPipeline
{
    public function upcast(
        string $eventName,
        int $fromVersion,
        DomainEvent $event,
        UpcastContext $context,
    ): DomainEvent;

    public function upcastTo(
        string $eventName,
        int $fromVersion,
        int $targetVersion,
        DomainEvent $event,
        UpcastContext $context,
    ): DomainEvent;
}
