<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Event\Hook;

use Monadial\Nexus\Ddd\Aggregate\Event\AggregateStreamId;
use Monadial\Nexus\Ddd\Aggregate\Event\StoredEvent;
use Throwable;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Dispatched when VersionedEventStore::appendIfVersion() throws — typically
 * an OptimisticLockException, but `$exception` is typed Throwable so any
 * failure surfaces here.
 */
final readonly class EventStoreAppendFailed extends EventStoreHookEvent
{
    /** @param list<StoredEvent> $events */
    public function __construct(
        AggregateStreamId $streamId,
        public int $expectedVersion,
        public array $events,
        public Throwable $exception,
    ) {
        parent::__construct($streamId);
    }
}
