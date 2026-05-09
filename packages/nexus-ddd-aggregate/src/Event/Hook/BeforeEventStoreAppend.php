<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Event\Hook;

use Monadial\Nexus\Ddd\Aggregate\Event\AggregateStreamId;
use Monadial\Nexus\Ddd\Aggregate\Event\StoredEvent;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Dispatched before VersionedEventStore::appendIfVersion() attempts the write,
 * before the version check.
 */
final readonly class BeforeEventStoreAppend extends EventStoreHookEvent
{
    /** @param list<StoredEvent> $events */
    public function __construct(AggregateStreamId $streamId, public int $expectedVersion, public array $events)
    {
        parent::__construct($streamId);
    }
}
