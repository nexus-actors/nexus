<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Tests\Unit\Inventory\Domain;

use Monadial\Nexus\Example\Fulfillment\Inventory\Domain\InventoryRules;
use Monadial\Nexus\Example\Fulfillment\Inventory\Domain\ItemState;
use Monadial\Nexus\Example\Fulfillment\Inventory\Domain\Rejection;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\ReleaseReservation;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\ReserveStock;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\Restock;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\Restocked;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\StockReleased;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\StockReservationRejected;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\StockReserved;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderId;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Quantity;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Sku;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\TenantId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(InventoryRules::class)]
final class InventoryRulesTest extends TestCase
{
    private TenantId $tenant;
    private Sku $sku;
    private OrderId $orderId;

    protected function setUp(): void
    {
        $this->tenant = TenantId::fromString('acme');
        $this->sku = Sku::fromString('WIDGET-42');
        $this->orderId = OrderId::generate();
    }

    // Row 1: any state + Restock → [Restocked]
    #[Test]
    public function restockAlwaysEmitsRestocked(): void
    {
        $state = ItemState::empty($this->tenant, $this->sku);
        $command = new Restock($this->tenant, $this->sku, Quantity::of(50));

        $decision = InventoryRules::decide($state, $command);

        self::assertIsArray($decision);
        self::assertCount(1, $decision);
        self::assertInstanceOf(Restocked::class, $decision[0]);
        self::assertSame(50, $decision[0]->quantity->value);
    }

    // Row 2: available ≥ qty, no reservation for orderId → [StockReserved]
    #[Test]
    public function reservingStockWhenAvailableEmitsStockReserved(): void
    {
        $state = ItemState::empty($this->tenant, $this->sku);
        $state = ItemState::evolve($state, new Restocked($this->tenant, $this->sku, Quantity::of(10)));

        $command = new ReserveStock($this->tenant, $this->sku, $this->orderId, Quantity::of(3));
        $decision = InventoryRules::decide($state, $command);

        self::assertIsArray($decision);
        self::assertCount(1, $decision);
        self::assertInstanceOf(StockReserved::class, $decision[0]);
        self::assertSame(3, $decision[0]->quantity->value);
    }

    // Row 3: reservation already exists for orderId → [] (idempotent)
    #[Test]
    public function reservingStockAgainForSameOrderIsIdempotent(): void
    {
        $state = ItemState::empty($this->tenant, $this->sku);
        $state = ItemState::evolve($state, new Restocked($this->tenant, $this->sku, Quantity::of(10)));
        $state = ItemState::evolve($state, new StockReserved($this->tenant, $this->sku, $this->orderId, Quantity::of(3)));

        $command = new ReserveStock($this->tenant, $this->sku, $this->orderId, Quantity::of(5));

        self::assertSame([], InventoryRules::decide($state, $command));
    }

    // Row 4: available < qty → [StockReservationRejected(requested, available, 'insufficient stock')]
    #[Test]
    public function reservingMoreThanAvailableEmitsStockReservationRejected(): void
    {
        $state = ItemState::empty($this->tenant, $this->sku);
        $state = ItemState::evolve($state, new Restocked($this->tenant, $this->sku, Quantity::of(2)));

        $command = new ReserveStock($this->tenant, $this->sku, $this->orderId, Quantity::of(5));
        $decision = InventoryRules::decide($state, $command);

        self::assertIsArray($decision);
        self::assertCount(1, $decision);
        self::assertInstanceOf(StockReservationRejected::class, $decision[0]);
        self::assertSame(5, $decision[0]->requested->value);
        self::assertSame(2, $decision[0]->available);
        self::assertSame('insufficient stock', $decision[0]->reason);
    }

    // Row 5: reservation exists → [StockReleased(original qty)]
    #[Test]
    public function releasingAnExistingReservationEmitsStockReleased(): void
    {
        $state = ItemState::empty($this->tenant, $this->sku);
        $state = ItemState::evolve($state, new Restocked($this->tenant, $this->sku, Quantity::of(10)));
        $state = ItemState::evolve($state, new StockReserved($this->tenant, $this->sku, $this->orderId, Quantity::of(3)));

        $command = new ReleaseReservation($this->tenant, $this->sku, $this->orderId);
        $decision = InventoryRules::decide($state, $command);

        self::assertIsArray($decision);
        self::assertCount(1, $decision);
        self::assertInstanceOf(StockReleased::class, $decision[0]);
        self::assertSame(3, $decision[0]->quantity->value);
    }

    // Row 6: no reservation for orderId → [] (idempotent)
    #[Test]
    public function releasingANonexistentReservationIsIdempotent(): void
    {
        $state = ItemState::empty($this->tenant, $this->sku);

        $command = new ReleaseReservation($this->tenant, $this->sku, $this->orderId);

        self::assertSame([], InventoryRules::decide($state, $command));
    }

    // Row 7: unknown command → Rejection
    #[Test]
    public function unknownCommandsAreRejected(): void
    {
        $state = ItemState::empty($this->tenant, $this->sku);

        self::assertInstanceOf(Rejection::class, InventoryRules::decide($state, new stdClass()));
    }
}
