<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Snapshot;

use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Aggregate\Event\AggregateStreamId;
use Monadial\Nexus\Ddd\Aggregate\Hook\NullEventDispatcher;
use Monadial\Nexus\Ddd\Aggregate\Snapshot\Hook\AfterSnapshotDelete;
use Monadial\Nexus\Ddd\Aggregate\Snapshot\Hook\AfterSnapshotLoad;
use Monadial\Nexus\Ddd\Aggregate\Snapshot\Hook\AfterSnapshotSave;
use Monadial\Nexus\Ddd\Aggregate\Snapshot\Hook\BeforeSnapshotDelete;
use Monadial\Nexus\Ddd\Aggregate\Snapshot\Hook\BeforeSnapshotLoad;
use Monadial\Nexus\Ddd\Aggregate\Snapshot\Hook\BeforeSnapshotSave;
use Monadial\Nexus\Ddd\Aggregate\Snapshot\Hook\SnapshotSaveFailed;
use Override;
use Psr\EventDispatcher\EventDispatcherInterface;
use Throwable;

/**
 * @psalm-api
 *
 * In-memory implementation. TESTS-ONLY (and single-process Fiber-only).
 * Stores only the latest snapshot per stream — `save()` overwrites the
 * existing slot. `delete()` clears the slot when its sequence number
 * is at or below `$upToSequenceNr`.
 */
final class InMemorySnapshotStore implements SnapshotStore
{
    /** @var array<string, Snapshot> */
    private array $snapshots = [];

    public function __construct(private readonly EventDispatcherInterface $events = new NullEventDispatcher()) {}

    #[Override]
    public function save(Snapshot $snapshot): void
    {
        $this->events->dispatch(new BeforeSnapshotSave($snapshot->streamId, $snapshot));

        try {
            $this->snapshots[$snapshot->streamId->toString()] = $snapshot;
        } catch (Throwable $e) {
            $this->events->dispatch(new SnapshotSaveFailed($snapshot->streamId, $snapshot, $e));

            throw $e;
        }

        $this->events->dispatch(new AfterSnapshotSave($snapshot->streamId, $snapshot));
    }

    /** @return Option<Snapshot> */
    #[Override]
    public function load(AggregateStreamId $streamId): Option
    {
        $this->events->dispatch(new BeforeSnapshotLoad($streamId));

        $loaded = Option::fromNullable($this->snapshots[$streamId->toString()] ?? null);

        $this->events->dispatch(new AfterSnapshotLoad($streamId, $loaded));

        return $loaded;
    }

    #[Override]
    public function delete(AggregateStreamId $streamId, int $upToSequenceNr): void
    {
        $this->events->dispatch(new BeforeSnapshotDelete($streamId, $upToSequenceNr));

        $key = $streamId->toString();

        if (isset($this->snapshots[$key]) && $this->snapshots[$key]->sequenceNr <= $upToSequenceNr) {
            unset($this->snapshots[$key]);
        }

        $this->events->dispatch(new AfterSnapshotDelete($streamId, $upToSequenceNr));
    }
}
