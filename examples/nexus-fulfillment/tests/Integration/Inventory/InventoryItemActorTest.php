<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Tests\Integration\Inventory;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Example\Fulfillment\Inventory\Application\InventoryItemActor;
use Monadial\Nexus\Example\Fulfillment\Inventory\Application\InventoryRefFactory;
use Monadial\Nexus\Example\Fulfillment\Inventory\Application\Reply\StockCommandAccepted;
use Monadial\Nexus\Example\Fulfillment\Inventory\Application\Reply\StockCommandRejected;
use Monadial\Nexus\Example\Fulfillment\Platform\Bus\ContextBusActor;
use Monadial\Nexus\Example\Fulfillment\Platform\Bus\Subscribe;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\ReleaseReservation;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\ReserveStock;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\Restock;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\StockReservationRejected;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\StockReserved;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderId;
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

#[CoversClass(InventoryItemActor::class)]
#[CoversClass(InventoryRefFactory::class)]
final class InventoryItemActorTest extends TestCase
{
    #[Test]
    public function restockThenReserveRepliesAcceptedWithCorrectAvailable(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('inv-test-1', $runtime);
        $store = new InMemoryEventStore();
        $snapshots = new InMemorySnapshotStore();
        $bus = $system->spawn(Props::fromBehavior(ContextBusActor::behavior()), 'context-bus');
        $factory = new InventoryRefFactory($system, $store, $snapshots, $bus, Duration::seconds(30));

        $tenantId = new TenantId('acme');
        $sku = new Sku('WIDGET-001');
        $orderId = OrderId::generate();

        /** @var StockCommandAccepted|null $restockReply */
        $restockReply = null;
        /** @var StockCommandAccepted|null $reserveReply */
        $reserveReply = null;

        $runtime->spawn(
            static function () use ($factory, $tenantId, $sku, $orderId, &$restockReply, &$reserveReply): void {
                $ref = $factory->of($tenantId, $sku);

                /** @var StockCommandAccepted $r1 */
                $r1 = $ref->ask(new Restock($tenantId, $sku, Quantity::of(100)), Duration::seconds(5))->await();
                $restockReply = $r1;

                /** @var StockCommandAccepted $r2 */
                $r2 = $ref->ask(new ReserveStock($tenantId, $sku, $orderId, Quantity::of(30)), Duration::seconds(5))->await();
                $reserveReply = $r2;
            },
        );

        $runtime->scheduleOnce(Duration::seconds(3), static fn() => $system->shutdown(Duration::seconds(1)));
        $system->run();

        self::assertInstanceOf(StockCommandAccepted::class, $restockReply);
        self::assertSame(100, $restockReply->onHand);
        self::assertSame(100, $restockReply->available);

        self::assertInstanceOf(StockCommandAccepted::class, $reserveReply);
        self::assertSame(100, $reserveReply->onHand);
        self::assertSame(70, $reserveReply->available);
    }

    #[Test]
    public function overReservePublishesRejectedEventAndSubsequentReserveWithinStockWorks(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('inv-test-2', $runtime);
        $store = new InMemoryEventStore();
        $snapshots = new InMemorySnapshotStore();
        $bus = $system->spawn(Props::fromBehavior(ContextBusActor::behavior()), 'context-bus');
        $factory = new InventoryRefFactory($system, $store, $snapshots, $bus, Duration::seconds(30));

        /** @var list<object> $busEvents */
        $busEvents = [];

        $probe = $system->spawn(
            Props::fromBehavior(
                Behavior::receive(
                    static function (ActorContext $ctx, object $msg) use (&$busEvents): Behavior {
                        $busEvents[] = $msg;

                        return Behavior::same();
                    },
                ),
            ),
            'probe',
        );

        $bus->tell(new Subscribe($probe));

        $tenantId = new TenantId('acme');
        $sku = new Sku('WIDGET-002');
        $orderId1 = OrderId::generate();
        $orderId2 = OrderId::generate();

        /** @var StockCommandAccepted|null $secondReserveReply */
        $secondReserveReply = null;

        $runtime->spawn(
            static function () use ($factory, $tenantId, $sku, $orderId1, $orderId2, &$secondReserveReply): void {
                $ref = $factory->of($tenantId, $sku);

                // Restock 50 units
                $ref->ask(new Restock($tenantId, $sku, Quantity::of(50)), Duration::seconds(5))->await();

                // Over-reserve: ask for 60 out of 50 available — the caller
                // receives a rejected reply while the rejection event goes to
                // the journal and the bus.
                $overReserveReply = $ref->ask(new ReserveStock($tenantId, $sku, $orderId1, Quantity::of(60)), Duration::seconds(5))->await();
                self::assertInstanceOf(StockCommandRejected::class, $overReserveReply);

                // Reserve within remaining stock — state is unchanged by rejected event
                /** @var StockCommandAccepted $r */
                $r = $ref->ask(new ReserveStock($tenantId, $sku, $orderId2, Quantity::of(30)), Duration::seconds(5))->await();
                $secondReserveReply = $r;
            },
        );

        $runtime->scheduleOnce(Duration::seconds(3), static fn() => $system->shutdown(Duration::seconds(1)));
        $system->run();

        // Bus must have received a StockReservationRejected for the over-reserve
        $rejectedEvents = array_filter($busEvents, static fn(object $e) => $e instanceof StockReservationRejected);
        self::assertCount(1, $rejectedEvents, 'bus should receive exactly one StockReservationRejected');

        // State is unchanged by rejected event: second reserve within 50-unit stock succeeds
        self::assertInstanceOf(StockCommandAccepted::class, $secondReserveReply);
        self::assertSame(50, $secondReserveReply->onHand);
        self::assertSame(20, $secondReserveReply->available);
    }

    #[Test]
    public function idempotentReserveProducesNoDuplicateBusEvent(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('inv-test-3', $runtime);
        $store = new InMemoryEventStore();
        $snapshots = new InMemorySnapshotStore();
        $bus = $system->spawn(Props::fromBehavior(ContextBusActor::behavior()), 'context-bus');
        $factory = new InventoryRefFactory($system, $store, $snapshots, $bus, Duration::seconds(30));

        /** @var list<object> $busEvents */
        $busEvents = [];

        $probe = $system->spawn(
            Props::fromBehavior(
                Behavior::receive(
                    static function (ActorContext $ctx, object $msg) use (&$busEvents): Behavior {
                        $busEvents[] = $msg;

                        return Behavior::same();
                    },
                ),
            ),
            'probe',
        );

        $bus->tell(new Subscribe($probe));

        $tenantId = new TenantId('acme');
        $sku = new Sku('WIDGET-003');
        $orderId = OrderId::generate();

        $runtime->spawn(
            static function () use ($factory, $tenantId, $sku, $orderId): void {
                $ref = $factory->of($tenantId, $sku);

                $ref->ask(new Restock($tenantId, $sku, Quantity::of(100)), Duration::seconds(5))->await();

                // First reserve — persists StockReserved
                $ref->ask(new ReserveStock($tenantId, $sku, $orderId, Quantity::of(10)), Duration::seconds(5))->await();

                // Idempotent retry with same orderId — no event persisted, no bus publication
                $ref->ask(new ReserveStock($tenantId, $sku, $orderId, Quantity::of(10)), Duration::seconds(5))->await();
            },
        );

        $runtime->scheduleOnce(Duration::seconds(3), static fn() => $system->shutdown(Duration::seconds(1)));
        $system->run();

        // Bus receives Restocked + StockReserved (2 total); the idempotent retry adds nothing
        $reservedEvents = array_filter($busEvents, static fn(object $e) => $e instanceof StockReserved);
        self::assertCount(1, $reservedEvents, 'idempotent reserve must not produce a second bus event');
    }

    #[Test]
    public function releaseRestoresAvailable(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('inv-test-4', $runtime);
        $store = new InMemoryEventStore();
        $snapshots = new InMemorySnapshotStore();
        $bus = $system->spawn(Props::fromBehavior(ContextBusActor::behavior()), 'context-bus');
        $factory = new InventoryRefFactory($system, $store, $snapshots, $bus, Duration::seconds(30));

        $tenantId = new TenantId('acme');
        $sku = new Sku('WIDGET-004');
        $orderId1 = OrderId::generate();
        $orderId2 = OrderId::generate();

        /** @var StockCommandAccepted|null $afterReleaseReply */
        $afterReleaseReply = null;

        $runtime->spawn(
            static function () use ($factory, $tenantId, $sku, $orderId1, $orderId2, &$afterReleaseReply): void {
                $ref = $factory->of($tenantId, $sku);

                // Restock 50 units
                $ref->ask(new Restock($tenantId, $sku, Quantity::of(50)), Duration::seconds(5))->await();

                // Reserve 30 → available = 20
                $ref->ask(new ReserveStock($tenantId, $sku, $orderId1, Quantity::of(30)), Duration::seconds(5))->await();

                // Release → available returns to 50
                $ref->ask(new ReleaseReservation($tenantId, $sku, $orderId1), Duration::seconds(5))->await();

                // Reserve 50 → available = 0 (proving release restored the 30)
                /** @var StockCommandAccepted $r */
                $r = $ref->ask(new ReserveStock($tenantId, $sku, $orderId2, Quantity::of(50)), Duration::seconds(5))->await();
                $afterReleaseReply = $r;
            },
        );

        $runtime->scheduleOnce(Duration::seconds(3), static fn() => $system->shutdown(Duration::seconds(1)));
        $system->run();

        self::assertInstanceOf(StockCommandAccepted::class, $afterReleaseReply);
        self::assertSame(50, $afterReleaseReply->onHand);
        self::assertSame(0, $afterReleaseReply->available);
    }

    #[Test]
    public function stateRecoversAfterPassivationViaReplay(): void
    {
        $passivateAfter = Duration::millis(50);
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('inv-test-5', $runtime);
        $store = new InMemoryEventStore();
        $snapshots = new InMemorySnapshotStore();
        $bus = $system->spawn(Props::fromBehavior(ContextBusActor::behavior()), 'context-bus');
        $factory = new InventoryRefFactory($system, $store, $snapshots, $bus, $passivateAfter);

        $tenantId = new TenantId('acme');
        $sku = new Sku('WIDGET-005');
        $orderId1 = OrderId::generate();
        $orderId2 = OrderId::generate();

        /** @var StockCommandAccepted|null $replayReply */
        $replayReply = null;

        // At 10ms: restock 100 and reserve 60 via tell (no reply) — events persist; actor will passivate
        $runtime->scheduleOnce(
            Duration::millis(10),
            static function () use ($factory, $tenantId, $sku, $orderId1): void {
                $ref = $factory->of($tenantId, $sku);
                $ref->tell(new Restock($tenantId, $sku, Quantity::of(100)));
                $ref->tell(new ReserveStock($tenantId, $sku, $orderId1, Quantity::of(60)));
            },
        );

        // At 200ms: actor has passivated (10ms + 50ms timeout well elapsed);
        // re-acquire via factory → replay → reserve remaining 40 → proves reservations map recovered
        $runtime->scheduleOnce(
            Duration::millis(200),
            static function () use ($runtime, $factory, $tenantId, $sku, $orderId2, &$replayReply): void {
                $runtime->spawn(
                    static function () use ($factory, $tenantId, $sku, $orderId2, &$replayReply): void {
                        $ref = $factory->of($tenantId, $sku);

                        /** @var StockCommandAccepted $r */
                        $r = $ref->ask(new ReserveStock($tenantId, $sku, $orderId2, Quantity::of(40)), Duration::seconds(5))->await();
                        $replayReply = $r;
                    },
                );
            },
        );

        $runtime->scheduleOnce(Duration::millis(800), static fn() => $system->shutdown(Duration::seconds(1)));
        $system->run();

        // reserve of 40 on top of existing reservation of 60 → onHand=100, available=0
        self::assertInstanceOf(StockCommandAccepted::class, $replayReply);
        self::assertSame(100, $replayReply->onHand);
        self::assertSame(0, $replayReply->available);
    }
}
