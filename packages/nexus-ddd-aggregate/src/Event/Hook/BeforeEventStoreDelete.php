<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Event\Hook;

use Monadial\Nexus\Ddd\Aggregate\Event\AggregateStreamId;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Dispatched before VersionedEventStore::deleteUpTo() removes events.
 */
final readonly class BeforeEventStoreDelete extends EventStoreHookEvent
{
    public function __construct(AggregateStreamId $streamId, public int $toSequenceNr)
    {
        parent::__construct($streamId);
    }
}
