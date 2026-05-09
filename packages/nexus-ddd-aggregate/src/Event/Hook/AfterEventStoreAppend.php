<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Event\Hook;

use Monadial\Nexus\Ddd\Aggregate\Event\AggregateStreamId;
use Monadial\Nexus\Ddd\Aggregate\Event\StoredEvent;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Dispatched after VersionedEventStore::appendIfVersion() commits successfully.
 * `$finalVersion` is the new highest sequence number after the append.
 */
final readonly class AfterEventStoreAppend
{
    /** @param list<StoredEvent> $events */
    public function __construct(public AggregateStreamId $streamId, public int $finalVersion, public array $events) {}
}
