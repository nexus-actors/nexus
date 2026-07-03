<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Tests\Unit\Orders\Domain;

use Monadial\Nexus\Example\Fulfillment\Orders\Domain\Command\CancelOrder;
use Monadial\Nexus\Example\Fulfillment\Orders\Domain\Command\PlaceOrder;
use Monadial\Nexus\Example\Fulfillment\Orders\Domain\OrderRules;
use Monadial\Nexus\Example\Fulfillment\Orders\Domain\OrderState;
use Monadial\Nexus\Example\Fulfillment\Orders\Domain\Rejection;
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

#[CoversClass(OrderRules::class)]
final class OrderRulesTest extends TestCase
{
    private TenantId $tenant;
    private OrderId $orderId;

    /** @var non-empty-list<OrderLine> */
    private array $lines;

    protected function setUp(): void
    {
        $this->tenant = TenantId::fromString('acme');
        $this->orderId = OrderId::generate();
        $this->lines = [
            new OrderLine(Sku::fromString('WIDGET-42'), Quantity::of(2), Money::of(1999, 'EUR')),
        ];
    }

    #[Test]
    public function placingANewOrderEmitsOrderPlacedWithComputedTotal(): void
    {
        $decision = OrderRules::decide($this->emptyState(), $this->place());

        self::assertIsArray($decision);
        self::assertCount(1, $decision);
        self::assertInstanceOf(OrderPlaced::class, $decision[0]);
        self::assertTrue($decision[0]->total->equals(Money::of(3998, 'EUR')));
    }

    #[Test]
    public function placingTwiceIsIdempotentNoNewEvents(): void
    {
        $placed = OrderState::evolve($this->emptyState(), $this->placedEvent());

        self::assertSame([], OrderRules::decide($placed, $this->place()));
    }

    #[Test]
    public function placingACancelledOrderIsRejected(): void
    {
        $cancelled = $this->cancelledState();

        self::assertInstanceOf(Rejection::class, OrderRules::decide($cancelled, $this->place()));
    }

    #[Test]
    public function cancellingAPlacedOrderEmitsOrderCancelled(): void
    {
        $placed = OrderState::evolve($this->emptyState(), $this->placedEvent());

        $decision = OrderRules::decide($placed, new CancelOrder($this->tenant, $this->orderId, 'customer request'));

        self::assertIsArray($decision);
        self::assertInstanceOf(OrderCancelled::class, $decision[0]);
        self::assertSame('customer request', $decision[0]->reason);
    }

    #[Test]
    public function cancellingTwiceIsIdempotentNoNewEvents(): void
    {
        $cancelled = $this->cancelledState();

        self::assertSame([], OrderRules::decide($cancelled, new CancelOrder($this->tenant, $this->orderId, 'again')));
    }

    #[Test]
    public function cancellingANonexistentOrderIsRejected(): void
    {
        $decision = OrderRules::decide($this->emptyState(), new CancelOrder($this->tenant, $this->orderId, 'nope'));

        self::assertInstanceOf(Rejection::class, $decision);
    }

    #[Test]
    public function unknownCommandsAreRejected(): void
    {
        self::assertInstanceOf(Rejection::class, OrderRules::decide($this->emptyState(), new stdClass()));
    }

    private function emptyState(): OrderState
    {
        return OrderState::empty($this->tenant, $this->orderId);
    }

    private function place(): PlaceOrder
    {
        return new PlaceOrder($this->tenant, $this->orderId, $this->lines);
    }

    private function placedEvent(): OrderPlaced
    {
        return new OrderPlaced($this->tenant, $this->orderId, $this->lines, Money::of(3998, 'EUR'));
    }

    private function cancelledState(): OrderState
    {
        $placed = OrderState::evolve($this->emptyState(), $this->placedEvent());

        return OrderState::evolve($placed, new OrderCancelled($this->tenant, $this->orderId, 'x'));
    }
}
