<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Snapshot\Hook;

use Monadial\Nexus\Ddd\Aggregate\Event\AggregateStreamId;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Dispatched after SnapshotStore::delete() completes.
 */
final readonly class AfterSnapshotDelete extends SnapshotHookEvent
{
    public function __construct(AggregateStreamId $streamId, public int $upToSequenceNr)
    {
        parent::__construct($streamId);
    }
}
