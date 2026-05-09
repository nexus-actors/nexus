<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Snapshot\Hook;

use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Aggregate\Event\AggregateStreamId;
use Monadial\Nexus\Ddd\Aggregate\Snapshot\Snapshot;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Dispatched after SnapshotStore::load() resolves. Carries the
 * `Option<Snapshot>` result — None when no snapshot exists.
 */
final readonly class AfterSnapshotLoad extends SnapshotHookEvent
{
    /** @param Option<Snapshot> $snapshot */
    public function __construct(AggregateStreamId $streamId, public Option $snapshot)
    {
        parent::__construct($streamId);
    }
}
