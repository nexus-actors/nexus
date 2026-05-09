<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Tests\Unit\Smoke\Fixtures;

use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;

/**
 * @psalm-immutable
 *
 * Smoke-test domain event fired when a line is appended to an existing
 * order. Replayed onto the aggregate via `OrderLines::withAdded()`.
 */
final readonly class OrderLineAdded implements DomainEvent
{
    public function __construct(public OrderId $orderId, public OrderLine $line) {}
}
