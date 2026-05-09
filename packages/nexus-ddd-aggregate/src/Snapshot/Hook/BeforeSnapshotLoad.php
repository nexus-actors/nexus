<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Snapshot\Hook;

use Monadial\Nexus\Ddd\Aggregate\Event\AggregateStreamId;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Dispatched before SnapshotStore::load() looks up a snapshot.
 */
final readonly class BeforeSnapshotLoad extends SnapshotHookEvent
{
    public function __construct(AggregateStreamId $streamId)
    {
        parent::__construct($streamId);
    }
}
