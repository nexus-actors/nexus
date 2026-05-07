<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Aggregate\Attribute;

use Attribute;

/**
 * @psalm-api
 *
 * Override the conventional `apply` + event-class-short-name lookup that
 * `ApplyDispatcher` performs. Place on a `DomainEvent` class to declare
 * the explicit name of the apply method on the aggregate.
 *
 * **Why this exists.** The default short-name convention works for the
 * 95% case but breaks the moment you version events. If you have
 * `App\Events\V1\OrderPlaced` and `App\Events\V2\OrderPlaced`, both
 * short-name to `OrderPlaced` and the dispatcher cannot route them to
 * distinct apply methods — `ApplyMethodAmbiguousException` at first
 * dispatch.
 *
 * The fix is explicit per-version routing:
 *
 *     namespace App\Events\V1;
 *     #[AppliesTo('applyOrderPlacedV1')]
 *     final readonly class OrderPlaced implements DomainEvent { ... }
 *
 *     namespace App\Events\V2;
 *     #[AppliesTo('applyOrderPlacedV2')]
 *     final readonly class OrderPlaced implements DomainEvent { ... }
 *
 * The aggregate then declares both `applyOrderPlacedV1` and
 * `applyOrderPlacedV2`, and the dispatcher routes each event to its
 * declared method without short-name conflict.
 *
 * Events without this attribute fall back to the default convention.
 *
 * Adopting this attribute does NOT replace event-upcasting strategies —
 * it just tells the dispatcher *where* to land each version. Upcasting
 * (transforming an old event payload into a newer schema before apply)
 * is still the event store / messaging layer's responsibility.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AppliesTo
{
    public function __construct(public string $methodName) {}
}
