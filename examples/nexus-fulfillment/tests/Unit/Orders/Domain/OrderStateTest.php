<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Tests\Unit\Orders\Domain;

use Monadial\Nexus\Example\Fulfillment\Orders\Domain\OrderState;
use Monadial\Nexus\Example\Fulfillment\Orders\Domain\OrderStatus;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Orders\OrderCancelled;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Orders\OrderPlaced;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Money;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderId;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderLine;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Quantity;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Sku;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\TenantId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(OrderState::class)]
final class OrderStateTest extends TestCase
{
    #[Test]
    public function startsNotCreatedAndFoldsThroughLifecycle(): void
    {
        $tenant = TenantId::fromString('acme');
        $id = OrderId::generate();
        $lines = [new OrderLine(Sku::fromString('A-1'), Quantity::of(1), Money::of(500, 'EUR'))];

        $state = OrderState::empty($tenant, $id);
        self::assertSame(OrderStatus::NotCreated, $state->status);
        self::assertNull($state->total);

        $state = OrderState::evolve($state, new OrderPlaced($tenant, $id, $lines, Money::of(500, 'EUR')));
        self::assertSame(OrderStatus::Placed, $state->status);
        self::assertTrue($state->total?->equals(Money::of(500, 'EUR')));

        $state = OrderState::evolve($state, new OrderCancelled($tenant, $id, 'why not'));
        self::assertSame(OrderStatus::Cancelled, $state->status);
        self::assertSame('why not', $state->cancelReason);
    }

    #[Test]
    public function unknownEventsLeaveStateUntouched(): void
    {
        $state = OrderState::empty(TenantId::fromString('acme'), OrderId::generate());

        self::assertSame($state, OrderState::evolve($state, new stdClass()));
    }
}
