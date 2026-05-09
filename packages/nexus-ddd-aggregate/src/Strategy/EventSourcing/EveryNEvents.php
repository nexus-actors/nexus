<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Strategy\EventSourcing;

use InvalidArgumentException;
use Monadial\Nexus\Ddd\Core\Aggregate\EventSourcedAggregateRoot;
use Override;

use function sprintf;

/**
 * @psalm-api
 *
 * Snapshot every N events. Triggers a snapshot when
 * `$eventCountSinceLastSnapshot >= $n`. Typical usage: `new EveryNEvents(50)`
 * for aggregates whose replay cost matters.
 */
final readonly class EveryNEvents implements SnapshotStrategy
{
    /**
     * @param int $n threshold; must be a positive integer
     *
     * @throws InvalidArgumentException when `$n < 1`
     */
    public function __construct(private int $n)
    {
        if ($n < 1) {
            throw new InvalidArgumentException(
                sprintf('EveryNEvents threshold must be positive, got %d.', $n),
            );
        }
    }

    #[Override]
    public function shouldSnapshot(EventSourcedAggregateRoot $aggregate, int $eventCountSinceLastSnapshot): bool
    {
        return $eventCountSinceLastSnapshot >= $this->n;
    }
}
