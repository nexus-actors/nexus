<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Event\Hook;

use Monadial\Nexus\Ddd\Aggregate\Event\AggregateStreamId;
use Monadial\Nexus\Ddd\Aggregate\Event\StoredEvent;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Dispatched after VersionedEventStore::load() materializes events.
 * Carries the loaded list so listeners can inspect what was read.
 */
final readonly class AfterEventStoreLoad
{
    /** @param list<StoredEvent> $events */
    public function __construct(
        public AggregateStreamId $streamId,
        public int $fromSequenceNr,
        public int $toSequenceNr,
        public array $events,
    ) {}
}
