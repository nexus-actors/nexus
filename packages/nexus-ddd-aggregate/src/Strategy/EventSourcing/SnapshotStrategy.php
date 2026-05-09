<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Strategy\EventSourcing;

use Monadial\Nexus\Ddd\Core\Aggregate\EventSourcedAggregateRoot;

/**
 * @psalm-api
 *
 * Consulted by `EventSourcingStrategy::persist()` AFTER a successful
 * versioned-append commit. Returns true if a snapshot SHOULD be written
 * at this point in the aggregate's lifecycle. The actual snapshot write
 * happens in a separate transaction (per v6 spec §25.6.4) so a failed
 * snapshot write does not roll back the events the aggregate just
 * committed.
 *
 * `$eventCountSinceLastSnapshot` = `aggregate.version() - lastSnapshotSequence`
 * (or `aggregate.version()` if no prior snapshot exists). The strategy
 * uses this to decide based on event-count thresholds.
 */
interface SnapshotStrategy
{
    public function shouldSnapshot(EventSourcedAggregateRoot $aggregate, int $eventCountSinceLastSnapshot): bool;
}
