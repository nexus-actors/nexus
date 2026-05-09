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
final readonly class BeforeEventStoreDelete
{
    public function __construct(public AggregateStreamId $streamId, public int $toSequenceNr) {}
}
