<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Event\Hook;

use Monadial\Nexus\Ddd\Aggregate\Event\AggregateStreamId;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Dispatched before VersionedEventStore::load() reads events.
 */
final readonly class BeforeEventStoreLoad extends EventStoreHookEvent
{
    public function __construct(AggregateStreamId $streamId, public int $fromSequenceNr, public int $toSequenceNr,) {
        parent::__construct($streamId);
    }
}
