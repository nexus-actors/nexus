<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Hook;

use Monadial\Nexus\Ddd\Aggregate\Event\AggregateStreamId;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Abstract base for all aggregate-persistence lifecycle hook events.
 * Listeners can subscribe at this level (`function(HookEvent $e)`)
 * to catch every hook fired by VersionedEventStore + SnapshotStore,
 * or at the more specific subtypes (`EventStoreHookEvent` /
 * `SnapshotHookEvent`) for narrower targeting.
 *
 * Every hook event carries the affected aggregate's stream id.
 */
abstract readonly class HookEvent
{
    public function __construct(public AggregateStreamId $streamId) {}
}
