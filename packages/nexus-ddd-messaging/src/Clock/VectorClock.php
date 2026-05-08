<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Clock;

use Monadial\Nexus\Ddd\Messaging\Identity\NodeId;

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
    #[\NoDiscard('tick() returns the advanced clock — discarding loses the increment')]
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
     * Counters are sorted by key so the result is canonical regardless of
     * which clock was `$this` (required for commutativity assertions).
     */
    #[\NoDiscard('merge() returns the merged clock — discarding loses the update')]
    public function merge(self $other): self
    {
        $merged = $this->counters;

        foreach ($other->counters as $node => $counter) {
            $merged[$node] = max($merged[$node] ?? 0, $counter);
        }

        ksort($merged);

        return new self($merged);
    }
}
