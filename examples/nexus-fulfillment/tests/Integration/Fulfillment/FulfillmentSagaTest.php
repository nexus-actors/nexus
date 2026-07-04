<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Tests\Integration\Fulfillment;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Example\Fulfillment\Fulfillment\Application\FulfillmentManagerActor;
use Monadial\Nexus\Example\Fulfillment\Fulfillment\Application\ProcessRefFactory;
use Monadial\Nexus\Example\Fulfillment\Inventory\Application\InventoryRefFactory;
use Monadial\Nexus\Example\Fulfillment\Inventory\Application\Reply\StockCommandAccepted;
use Monadial\Nexus\Example\Fulfillment\Orders\Application\OrderRefFactory;
use Monadial\Nexus\Example\Fulfillment\Orders\Application\Reply\OrderAccepted;
use Monadial\Nexus\Example\Fulfillment\Orders\Application\Reply\OrderRejected;
use Monadial\Nexus\Example\Fulfillment\Orders\Domain\Command\PlaceOrder;
use Monadial\Nexus\Example\Fulfillment\Orders\Domain\OrderStatus;
use Monadial\Nexus\Example\Fulfillment\Platform\Bus\ContextBusActor;
use Monadial\Nexus\Example\Fulfillment\Platform\Bus\Subscribe;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\ReserveStock;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\Restock;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\StockReserved;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Orders\OrderPlaced;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Money;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderId;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderLine;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Quantity;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Sku;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\TenantId;
use Monadial\Nexus\Persistence\Event\InMemoryEventStore;
use Monadial\Nexus\Persistence\Snapshot\InMemorySnapshotStore;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(FulfillmentManagerActor::class)]
#[CoversClass(ProcessRefFactory::class)]
final class FulfillmentSagaTest extends TestCase
{
    /**
     * (a) Happy path: 2 SKUs, both have stock → order becomes stock_reserved.
     * Asserted by idempotent PlaceOrder ask returning OrderAccepted{StockReserved}.
     */
    #[Test]
    public function happyPathTwoSkusOrderBecomesStockReserved(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('saga-test-1', $runtime);
        $store = new InMemoryEventStore();
        $snapshots = new InMemorySnapshotStore();

        $bus = $system->spawn(Props::fromBehavior(ContextBusActor::behavior()), 'context-bus');

        $tenantId = TenantId::fromString('acme');
        $orderId = OrderId::generate();
        $skuA = Sku::fromString('SAGA-AAA');
        $skuB = Sku::fromString('SAGA-BBB');

        $invFactory = new InventoryRefFactory($system, $store, $snapshots, $bus, Duration::seconds(30));
        $orderFactory = new OrderRefFactory($system, $store, $snapshots, $bus, Duration::seconds(30));
        $processFactory = new ProcessRefFactory(
            $system,
            $store,
            $snapshots,
            $orderFactory,
            $invFactory,
            Duration::seconds(30),
        );

        $manager = $system->spawn(
            Props::fromBehavior(FulfillmentManagerActor::behavior($processFactory)),
            FulfillmentManagerActor::ACTOR_NAME,
        );

        $bus->tell(new Subscribe($manager));

        $lines = [
            new OrderLine($skuA, Quantity::of(2), new Money(1000, 'EUR')),
            new OrderLine($skuB, Quantity::of(3), new Money(2000, 'EUR')),
        ];
        $placeOrder = new PlaceOrder($tenantId, $orderId, $lines);

        /** @var OrderAccepted|null $orderStatus */
        $orderStatus = null;

        // Restock both SKUs first, then place the order
        $runtime->spawn(
            static function () use ($invFactory, $tenantId, $skuA, $skuB): void {
                $invFactory->of($tenantId, $skuA)->ask(new Restock($tenantId, $skuA, Quantity::of(10)), Duration::seconds(5))->await();
                $invFactory->of($tenantId, $skuB)->ask(new Restock($tenantId, $skuB, Quantity::of(10)), Duration::seconds(5))->await();
            },
        );

        // At t=200ms: place the order (order entity publishes OrderPlaced to bus → manager → saga)
        $runtime->scheduleOnce(
            Duration::millis(200),
            static function () use ($orderFactory, $tenantId, $orderId, $placeOrder): void {
                $orderFactory->of($tenantId, $orderId)->tell($placeOrder);
            },
        );

        // At t=2500ms: idempotent PlaceOrder ask — on StockReserved order returns OrderAccepted
        $runtime->scheduleOnce(
            Duration::millis(2500),
            static function () use ($runtime, $orderFactory, $tenantId, $orderId, $placeOrder, &$orderStatus): void {
                $runtime->spawn(
                    static function () use ($orderFactory, $tenantId, $orderId, $placeOrder, &$orderStatus): void {
                        /** @var OrderAccepted $r */
                        $r = $orderFactory->of($tenantId, $orderId)->ask($placeOrder, Duration::seconds(5))->await();
                        $orderStatus = $r;
                    },
                );
            },
        );

        $runtime->scheduleOnce(Duration::seconds(4), static fn() => $system->shutdown(Duration::seconds(1)));
        $system->run();

        self::assertInstanceOf(OrderAccepted::class, $orderStatus);
        self::assertSame(OrderStatus::StockReserved, $orderStatus->status);
    }

    /**
     * (b) Compensation: 1 sufficient + 1 insufficient SKU → order cancelled,
     * and the sufficient SKU's availability is RESTORED (release worked).
     *
     * Assertion strategy for cancelled status: PlaceOrder on Cancelled order returns
     * OrderRejected (not OrderAccepted). The probe inventory reserve confirms
     * the compensation released the held stock.
     */
    #[Test]
    public function compensationOneInsufficientSkuCancelsOrderAndReleasesReservations(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('saga-test-2', $runtime);
        $store = new InMemoryEventStore();
        $snapshots = new InMemorySnapshotStore();

        $bus = $system->spawn(Props::fromBehavior(ContextBusActor::behavior()), 'context-bus');

        $tenantId = TenantId::fromString('acme');
        $orderId = OrderId::generate();
        $probeOrderId = OrderId::generate();
        $skuA = Sku::fromString('SAGA-CCC');
        $skuB = Sku::fromString('SAGA-DDD');

        $invFactory = new InventoryRefFactory($system, $store, $snapshots, $bus, Duration::seconds(30));
        $orderFactory = new OrderRefFactory($system, $store, $snapshots, $bus, Duration::seconds(30));
        $processFactory = new ProcessRefFactory(
            $system,
            $store,
            $snapshots,
            $orderFactory,
            $invFactory,
            Duration::seconds(30),
        );

        $manager = $system->spawn(
            Props::fromBehavior(FulfillmentManagerActor::behavior($processFactory)),
            FulfillmentManagerActor::ACTOR_NAME,
        );

        $bus->tell(new Subscribe($manager));

        // skuA: 5 units available; skuB: intentionally NOT restocked → insufficient stock
        $lines = [
            new OrderLine($skuA, Quantity::of(5), new Money(1000, 'EUR')),
            new OrderLine($skuB, Quantity::of(3), new Money(2000, 'EUR')),
        ];
        $placeOrder = new PlaceOrder($tenantId, $orderId, $lines);

        /** @var OrderRejected|null $cancelledReply */
        $cancelledReply = null;
        /** @var StockCommandAccepted|null $probeReserveReply */
        $probeReserveReply = null;

        // Only restock skuA — skuB stays at 0 (will cause StockReservationRejected)
        $runtime->spawn(
            static function () use ($invFactory, $tenantId, $skuA): void {
                $invFactory->of($tenantId, $skuA)->ask(new Restock($tenantId, $skuA, Quantity::of(5)), Duration::seconds(5))->await();
            },
        );

        // At t=200ms: place order with 2 lines (skuA=5 has stock, skuB=3 has none)
        $runtime->scheduleOnce(
            Duration::millis(200),
            static function () use ($orderFactory, $tenantId, $orderId, $placeOrder): void {
                $orderFactory->of($tenantId, $orderId)->tell($placeOrder);
            },
        );

        // At t=2500ms: assert order cancelled and skuA reservation released
        $runtime->scheduleOnce(
            Duration::millis(2500),
            static function () use ($runtime, $orderFactory, $invFactory, $tenantId, $orderId, $probeOrderId, $skuA, $placeOrder, &$cancelledReply, &$probeReserveReply): void {
                $runtime->spawn(
                    static function () use ($orderFactory, $invFactory, $tenantId, $orderId, $probeOrderId, $skuA, $placeOrder, &$cancelledReply, &$probeReserveReply): void {
                        // PlaceOrder on Cancelled order → OrderRejected (confirms cancellation)
                        /** @var OrderRejected $r */
                        $r = $orderFactory->of($tenantId, $orderId)->ask($placeOrder, Duration::seconds(5))->await();
                        $cancelledReply = $r;

                        // Probe: reserve all 5 units of skuA with a fresh orderId.
                        // If compensation released skuA's reservation, this succeeds.
                        /** @var StockCommandAccepted $probe */
                        $probe = $invFactory->of($tenantId, $skuA)->ask(
                            new ReserveStock($tenantId, $skuA, $probeOrderId, Quantity::of(5)),
                            Duration::seconds(5),
                        )->await();
                        $probeReserveReply = $probe;
                    },
                );
            },
        );

        $runtime->scheduleOnce(Duration::seconds(4), static fn() => $system->shutdown(Duration::seconds(1)));
        $system->run();

        // PlaceOrder on Cancelled order returns OrderRejected
        self::assertInstanceOf(OrderRejected::class, $cancelledReply);
        self::assertStringContainsString('cancelled', $cancelledReply->reason);

        // skuA reservation was released: probe reserve of all 5 units succeeds
        self::assertInstanceOf(StockCommandAccepted::class, $probeReserveReply);
        self::assertSame(5, $probeReserveReply->onHand);
        self::assertSame(0, $probeReserveReply->available);
    }

    /**
     * (c) Saga replay: stop saga after FulfillmentStarted persisted (short passivation),
     * then deliver StockReserved events via the manager — respawned saga completes
     * and order reaches stock_reserved.
     *
     * Replay mechanism: manager NOT subscribed to bus, so inventory's auto-published
     * StockReserved events don't reach the manager. Saga idles and passivates (50ms).
     * StockReserved events are then delivered manually at t=600ms to prove replay.
     */
    #[Test]
    public function sagaReplayResumesAndCompletesAfterPassivation(): void
    {
        $passivateAfter = Duration::millis(50);
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('saga-test-3', $runtime);
        $store = new InMemoryEventStore();
        $snapshots = new InMemorySnapshotStore();

        // Bus used only for inventory publication; manager NOT subscribed.
        $bus = $system->spawn(Props::fromBehavior(ContextBusActor::behavior()), 'context-bus');

        $tenantId = TenantId::fromString('acme');
        $orderId = OrderId::generate();
        $skuA = Sku::fromString('SAGA-EEE');
        $skuB = Sku::fromString('SAGA-FFF');

        $invFactory = new InventoryRefFactory($system, $store, $snapshots, $bus, Duration::seconds(30));
        $orderFactory = new OrderRefFactory($system, $store, $snapshots, $bus, Duration::seconds(30));
        $processFactory = new ProcessRefFactory(
            $system,
            $store,
            $snapshots,
            $orderFactory,
            $invFactory,
            $passivateAfter,
        );

        $manager = $system->spawn(
            Props::fromBehavior(FulfillmentManagerActor::behavior($processFactory)),
            FulfillmentManagerActor::ACTOR_NAME,
        );

        // Manager NOT subscribed to bus — inventory's StockReserved won't auto-route to saga.

        $lines = [
            new OrderLine($skuA, Quantity::of(2), new Money(1000, 'EUR')),
            new OrderLine($skuB, Quantity::of(3), new Money(2000, 'EUR')),
        ];
        $placeOrder = new PlaceOrder($tenantId, $orderId, $lines);
        $orderPlacedEvent = new OrderPlaced(
            $tenantId,
            $orderId,
            $lines,
            new Money(8000, 'EUR'),
        );

        /** @var OrderAccepted|null $orderStatus */
        $orderStatus = null;

        // Restock both SKUs (inventory will reserve stock when saga sends ReserveStock,
        // but StockReserved responses won't reach the manager/saga).
        $runtime->spawn(
            static function () use ($invFactory, $tenantId, $skuA, $skuB): void {
                $invFactory->of($tenantId, $skuA)->ask(new Restock($tenantId, $skuA, Quantity::of(10)), Duration::seconds(5))->await();
                $invFactory->of($tenantId, $skuB)->ask(new Restock($tenantId, $skuB, Quantity::of(10)), Duration::seconds(5))->await();
            },
        );

        // At t=100ms: place the order so the entity exists for MarkStockReserved later
        $runtime->scheduleOnce(
            Duration::millis(100),
            static function () use ($orderFactory, $tenantId, $orderId, $placeOrder): void {
                $orderFactory->of($tenantId, $orderId)->tell($placeOrder);
            },
        );

        // At t=300ms: tell manager the OrderPlaced contract (saga starts, persists
        // FulfillmentStarted, tells inventory ReserveStock).
        // Inventory responds with StockReserved on the bus — nobody listens.
        // Saga idles → passivates at t=350ms (300ms + 50ms timeout).
        $runtime->scheduleOnce(
            Duration::millis(300),
            static function () use ($manager, $orderPlacedEvent): void {
                $manager->tell($orderPlacedEvent);
            },
        );

        // At t=700ms: saga has passivated (well past 350ms).
        // Deliver StockReserved × 2 manually — manager routes to the respawned saga
        // which replays FulfillmentStarted from journal, then processes these events.
        $runtime->scheduleOnce(
            Duration::millis(700),
            static function () use ($manager, $tenantId, $orderId, $skuA, $skuB): void {
                $manager->tell(new StockReserved($tenantId, $skuA, $orderId, Quantity::of(2)));
                $manager->tell(new StockReserved($tenantId, $skuB, $orderId, Quantity::of(3)));
            },
        );

        // At t=2500ms: idempotent PlaceOrder ask — on StockReserved order returns OrderAccepted
        $runtime->scheduleOnce(
            Duration::millis(2500),
            static function () use ($runtime, $orderFactory, $tenantId, $orderId, $placeOrder, &$orderStatus): void {
                $runtime->spawn(
                    static function () use ($orderFactory, $tenantId, $orderId, $placeOrder, &$orderStatus): void {
                        /** @var OrderAccepted $r */
                        $r = $orderFactory->of($tenantId, $orderId)->ask($placeOrder, Duration::seconds(5))->await();
                        $orderStatus = $r;
                    },
                );
            },
        );

        $runtime->scheduleOnce(Duration::seconds(4), static fn() => $system->shutdown(Duration::seconds(1)));
        $system->run();

        self::assertInstanceOf(OrderAccepted::class, $orderStatus);
        self::assertSame(OrderStatus::StockReserved, $orderStatus->status);
    }
}
