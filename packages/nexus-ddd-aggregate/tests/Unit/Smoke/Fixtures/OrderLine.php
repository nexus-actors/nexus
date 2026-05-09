<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Tests\Unit\Smoke\Fixtures;

/**
 * @psalm-immutable
 *
 * Smoke-test value object for a single line of an order: name + quantity +
 * unit price. Plain readonly fields — equality and serialization are not
 * exercised by the smoke pipeline (those are the messaging package's
 * concern), so we don't add behavior beyond construction.
 */
final readonly class OrderLine
{
    public function __construct(public string $name, public int $quantity, public int $price) {}
}
