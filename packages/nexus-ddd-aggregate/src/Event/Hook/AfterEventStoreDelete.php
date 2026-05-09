<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Event\Hook;

use Monadial\Nexus\Ddd\Aggregate\Event\AggregateStreamId;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Dispatched after VersionedEventStore::deleteUpTo() completes.
 */
final readonly class AfterEventStoreDelete
{
    public function __construct(public AggregateStreamId $streamId, public int $toSequenceNr) {}
}
