<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Snapshot\Hook;

use Monadial\Nexus\Ddd\Aggregate\Event\AggregateStreamId;
use Monadial\Nexus\Ddd\Aggregate\Snapshot\Snapshot;
use Throwable;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Dispatched when SnapshotStore::save() throws.
 */
final readonly class SnapshotSaveFailed extends SnapshotHookEvent
{
    public function __construct(AggregateStreamId $streamId, public Snapshot $snapshot, public Throwable $exception,) {
        parent::__construct($streamId);
    }
}
