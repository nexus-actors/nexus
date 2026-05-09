<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Tests\Unit\Smoke\Fixtures;

use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;

/**
 * @psalm-immutable
 *
 * Smoke-test domain event fired when an order is placed. Carries the
 * triple of (order id, customer id, initial lines) so {@see Order} can
 * rebuild state from a single event.
 */
final readonly class OrderPlaced implements DomainEvent
{
    public function __construct(public OrderId $orderId, public CustomerId $customerId, public OrderLines $lines) {}
}
