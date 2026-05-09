<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Versioning;

/**
 * @psalm-api
 *
 * @template TIn of object
 * @template TOut of object
 *
 * Pure transformation `(snapshotStateV_n, context) → snapshotStateV_n+1`
 * for ONE snapshot stateVersion transition. Like `Upcaster` for events,
 * snapshot upcasters work with typed state objects on both sides — the
 * persistence layer Valinor-maps the persisted JSON to the typed v_n
 * state class BEFORE the upcaster sees it, so the upcaster never
 * touches arrays.
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
     * @param TIn $state
     * @return TOut
     */
    public function upcast(object $state, UpcastContext $context): object;
}
