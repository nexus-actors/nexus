<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Strategy\EventSourcing;

use Closure;
use Monadial\Nexus\Ddd\Core\Aggregate\EventSourcedAggregateRoot;
use Override;

/**
 * @psalm-api
 *
 * Custom-predicate snapshot strategy. The closure receives the aggregate
 * and the event-count-since-last-snapshot; returns true to trigger a
 * snapshot. Use for domain-specific decisions (e.g., "snapshot when an
 * Order moves to Completed status").
 */
final readonly class OnPredicate implements SnapshotStrategy
{
    /** @var Closure(EventSourcedAggregateRoot, int): bool */
    private Closure $predicate;

    /** @param callable(EventSourcedAggregateRoot, int): bool $predicate */
    public function __construct(callable $predicate)
    {
        $this->predicate = $predicate(...);
    }

    #[Override]
    public function shouldSnapshot(EventSourcedAggregateRoot $aggregate, int $eventCountSinceLastSnapshot): bool
    {
        return ($this->predicate)($aggregate, $eventCountSinceLastSnapshot);
    }
}
