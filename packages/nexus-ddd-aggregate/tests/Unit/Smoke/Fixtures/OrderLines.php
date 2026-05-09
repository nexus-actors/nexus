<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Tests\Unit\Smoke\Fixtures;

use function array_merge;
use function count;

/**
 * @psalm-immutable
 *
 * Smoke-test value-object collection of {@see OrderLine}. Immutable —
 * `withAdded()` returns a new instance with the line appended. Used by
 * {@see Order} to build up state event-by-event in `apply()`.
 */
final readonly class OrderLines
{
    /** @param list<OrderLine> $lines */
    public function __construct(public array $lines) {}

    public function withAdded(OrderLine $line): self
    {
        return new self(array_merge($this->lines, [$line]));
    }

    public function count(): int
    {
        return count($this->lines);
    }
}
