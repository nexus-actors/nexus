<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Clock;

use Monadial\Nexus\Ddd\Messaging\Identity\NodeId;
use NoDiscard;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Vector clock — partial order over events across distributed nodes.
 * Standard Lamport-Mattern algorithm:
 *   - On send: sender ticks its own counter, stamps the message
 *   - On receive: receiver merges (pointwise max) incoming clock
 *     with its own, then ticks its own counter
 */
final readonly class VectorClock
{
    /**
     * @param array<string, int> $counters NodeId.value() => positive counter
     */
    public function __construct(public array $counters) {}

    public static function empty(): self
    {
        return new self([]);
    }

    /** Increment this node's counter. Called when the node SENDS a message. */
    #[NoDiscard('tick() returns the advanced clock — discarding loses the increment')]
    public function tick(NodeId $node): self
    {
        $next = $this->counters;
        $key = $node->value();
        $next[$key] = ($next[$key] ?? 0) + 1;

        return new self($next);
    }

    /**
     * Merge with another clock (pointwise max). Called on RECEIVE before
     * the local node ticks its own counter.
     */
    #[NoDiscard('merge() returns the merged clock — discarding loses the update')]
    public function merge(self $other): self
    {
        $merged = $this->counters;

        foreach ($other->counters as $node => $counter) {
            $merged[$node] = max($merged[$node] ?? 0, $counter);
        }

        return new self($merged);
    }

    /**
     * Pointwise compare two clocks. Result is the partial-order relation
     * between this and `$other`.
     */
    public function compareTo(self $other): VectorClockOrdering
    {
        $hasLess = false;
        $hasGreater = false;
        $allKeys = array_unique([...array_keys($this->counters), ...array_keys($other->counters)]);

        foreach ($allKeys as $node) {
            $a = $this->counters[$node] ?? 0;
            $b = $other->counters[$node] ?? 0;

            if ($a < $b) {
                $hasLess = true;
            }

            if ($a > $b) {
                $hasGreater = true;
            }
        }

        return match (true) {
            $hasLess && $hasGreater => VectorClockOrdering::Concurrent,
            $hasLess => VectorClockOrdering::HappensBefore,
            $hasGreater => VectorClockOrdering::HappensAfter,
            default => VectorClockOrdering::Equal,
        };
    }
}
