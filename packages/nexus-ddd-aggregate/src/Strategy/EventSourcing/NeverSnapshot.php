<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Strategy\EventSourcing;

use Monadial\Nexus\Ddd\Core\Aggregate\EventSourcedAggregateRoot;
use Override;

/**
 * @psalm-api
 *
 * Default snapshot strategy: never snapshot. Apps that want snapshots
 * opt into `EveryNEvents` or a custom `OnPredicate`.
 */
final readonly class NeverSnapshot implements SnapshotStrategy
{
    #[Override]
    public function shouldSnapshot(EventSourcedAggregateRoot $aggregate, int $eventCountSinceLastSnapshot): bool
    {
        return false;
    }
}
