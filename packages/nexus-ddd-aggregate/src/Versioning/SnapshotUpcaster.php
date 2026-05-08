<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Versioning;

/**
 * @psalm-api
 *
 * Pure transformation `(snapshotStateV_n, context) → snapshotStateV_n+1`
 * for ONE snapshot stateVersion transition. Snapshots store the
 * aggregate's full state at a sequence number; bumping `stateVersion()`
 * on the aggregate (because state shape changed) requires a snapshot
 * upcaster to migrate previously-written snapshots.
 *
 * Same purity rules as `Upcaster` — no clock, no RNG, no logger, no
 * aggregate state access.
 *
 * Per v6 spec §10.3.1: if a snapshot's stateVersion mismatches the
 * aggregate's current stateVersion AND no upcaster covers the gap,
 * the framework falls back to FULL REPLAY from event 1 (does NOT throw).
 */
interface SnapshotUpcaster
{
    /** @return class-string */
    public function aggregateClass(): string;

    public function fromStateVersion(): int;

    public function toStateVersion(): int;

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public function upcast(array $state, PayloadContext $context): array;
}
