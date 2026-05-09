<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Snapshot\Hook;

use Monadial\Nexus\Ddd\Aggregate\Event\AggregateStreamId;
use Monadial\Nexus\Ddd\Aggregate\Snapshot\Snapshot;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Dispatched after SnapshotStore::save() commits successfully.
 */
final readonly class AfterSnapshotSave
{
    public function __construct(public AggregateStreamId $streamId, public Snapshot $snapshot) {}
}
