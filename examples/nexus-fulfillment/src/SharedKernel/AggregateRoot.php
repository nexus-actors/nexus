<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\SharedKernel;

/**
 * Marker interface for mutable aggregate roots used with AggregateBehavior.
 *
 * The persistence engine folds persisted events through apply(); handler
 * methods call record() internally and never apply() directly (no double-apply).
 * AggregateBehavior discovers command handlers by method signature convention.
 */
interface AggregateRoot
{
    /**
     * Apply a persisted event to this aggregate's state.
     * MUST NOT be called from record() to prevent double-apply.
     */
    public function apply(object $event): void;

    /**
     * Drain and return all recorded events. Must be called once per command.
     *
     * @return list<object>
     */
    public function releaseEvents(): array;
}
