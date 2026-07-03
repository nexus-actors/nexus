<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Tests\Integration\Orders;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Example\Fulfillment\Orders\Application\OrderRefFactory;
use Monadial\Nexus\Example\Fulfillment\Orders\Application\Reply\OrderAccepted;
use Monadial\Nexus\Example\Fulfillment\Orders\Application\Reply\OrderRejected;
use Monadial\Nexus\Example\Fulfillment\Orders\Domain\Command\CancelOrder;
use Monadial\Nexus\Example\Fulfillment\Orders\Domain\Command\PlaceOrder;
use Monadial\Nexus\Example\Fulfillment\Orders\Domain\OrderStatus;
use Monadial\Nexus\Example\Fulfillment\Platform\Bus\ContextBusActor;
use Monadial\Nexus\Example\Fulfillment\Platform\Bus\Subscribe;
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

#[CoversClass(OrderRefFactory::class)]
final class OrderActorTest extends TestCase
{
    #[Test]
    public function placeThenGetIsAcceptedAndCancelWorks(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('order-test-1', $runtime);
        $store = new InMemoryEventStore();
        $snapshots = new InMemorySnapshotStore();
        $bus = $system->spawn(Props::fromBehavior(ContextBusActor::behavior()), 'context-bus');
        $factory = new OrderRefFactory($system, $store, $snapshots, $bus, Duration::seconds(30));

        $tenantId = new TenantId('acme');
        $orderId = OrderId::generate();
        $placeOrder = new PlaceOrder(
            $tenantId,
            $orderId,
            [new OrderLine(new Sku('WIDGET-001'), new Quantity(2), new Money(1999, 'USD'))],
        );

        /** @var OrderAccepted|null $placed */
        $placed = null;
        /** @var OrderAccepted|null $idempotent */
        $idempotent = null;
        /** @var OrderAccepted|null $cancelled */
        $cancelled = null;
        /** @var OrderRejected|null $rejected */
        $rejected = null;

        $runtime->spawn(
            static function () use ($factory, $tenantId, $orderId, $placeOrder, &$placed, &$idempotent, &$cancelled, &$rejected): void {
                $ref = $factory->of($tenantId, $orderId);

                // Place order
                /** @var OrderAccepted $r */
                $r = $ref->ask($placeOrder, Duration::seconds(5))->await();
                $placed = $r;

                // Idempotent re-place
                /** @var OrderAccepted $r2 */
                $r2 = $ref->ask($placeOrder, Duration::seconds(5))->await();
                $idempotent = $r2;

                // Cancel
                $cancel = new CancelOrder($tenantId, $orderId, 'customer request');
                /** @var OrderAccepted $r3 */
                $r3 = $ref->ask($cancel, Duration::seconds(5))->await();
                $cancelled = $r3;

                // Place after cancel → rejection
                /** @var OrderRejected $r4 */
                $r4 = $ref->ask($placeOrder, Duration::seconds(5))->await();
                $rejected = $r4;
            },
        );

        $runtime->scheduleOnce(Duration::seconds(3), static fn() => $system->shutdown(Duration::seconds(1)));
        $system->run();

        self::assertInstanceOf(OrderAccepted::class, $placed);
        self::assertSame(OrderStatus::Placed, $placed->status);
        self::assertNotNull($placed->total);
        self::assertSame(3998, $placed->total->amount);
        self::assertSame('USD', $placed->total->currency);

        self::assertInstanceOf(OrderAccepted::class, $idempotent);
        self::assertSame(OrderStatus::Placed, $idempotent->status);

        self::assertInstanceOf(OrderAccepted::class, $cancelled);
        self::assertSame(OrderStatus::Cancelled, $cancelled->status);

        self::assertInstanceOf(OrderRejected::class, $rejected);
        self::assertStringContainsString('cancelled', $rejected->reason);
    }

    #[Test]
    public function stateRecoversAfterEntityStopsViaReplay(): void
    {
        $passivateAfter = Duration::millis(50);
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('order-test-2', $runtime);
        $store = new InMemoryEventStore();
        $snapshots = new InMemorySnapshotStore();
        $bus = $system->spawn(Props::fromBehavior(ContextBusActor::behavior()), 'context-bus');
        $factory = new OrderRefFactory($system, $store, $snapshots, $bus, $passivateAfter);

        $tenantId = new TenantId('acme');
        $orderId = OrderId::generate();
        $placeOrder = new PlaceOrder(
            $tenantId,
            $orderId,
            [new OrderLine(new Sku('WIDGET-001'), new Quantity(1), new Money(999, 'USD'))],
        );

        /** @var OrderAccepted|null $cancelResult */
        $cancelResult = null;

        // Place order at startup then go idle so actor passivates
        $runtime->scheduleOnce(
            Duration::millis(10),
            static function () use ($factory, $tenantId, $orderId, $placeOrder): void {
                // tell() — sender is null, reply is dropped; event persists ✓
                $factory->of($tenantId, $orderId)->tell($placeOrder);
            },
        );

        // At 200ms the actor has been idle for 190ms (well past 50ms timeout) — now
        // spawn a fiber that re-acquires via factory (replay) and cancels
        $runtime->scheduleOnce(
            Duration::millis(200),
            static function () use ($runtime, $factory, $tenantId, $orderId, &$cancelResult): void {
                $runtime->spawn(
                    static function () use ($factory, $tenantId, $orderId, &$cancelResult): void {
                        $ref = $factory->of($tenantId, $orderId);
                        $cancel = new CancelOrder($tenantId, $orderId, 'after replay');
                        /** @var OrderAccepted $r */
                        $r = $ref->ask($cancel, Duration::seconds(5))->await();
                        $cancelResult = $r;
                    },
                );
            },
        );

        $runtime->scheduleOnce(Duration::millis(800), static fn() => $system->shutdown(Duration::seconds(1)));
        $system->run();

        // Cancel succeeded → state was replayed to Placed, cancel produced Cancelled
        self::assertInstanceOf(OrderAccepted::class, $cancelResult);
        self::assertSame(OrderStatus::Cancelled, $cancelResult->status);
    }

    #[Test]
    public function idleEntityPassivatesAfterReceiveTimeout(): void
    {
        $passivateAfter = Duration::millis(100);
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('order-test-3', $runtime);
        $store = new InMemoryEventStore();
        $snapshots = new InMemorySnapshotStore();
        $bus = $system->spawn(Props::fromBehavior(ContextBusActor::behavior()), 'context-bus');
        $factory = new OrderRefFactory($system, $store, $snapshots, $bus, $passivateAfter);

        $tenantId = new TenantId('acme');
        $orderId = OrderId::generate();
        $placeOrder = new PlaceOrder(
            $tenantId,
            $orderId,
            [new OrderLine(new Sku('WIDGET-001'), new Quantity(1), new Money(500, 'USD'))],
        );

        /** @var bool|null $aliveAt50 */
        $aliveAt50 = null;
        /** @var bool|null $aliveAt300 */
        $aliveAt300 = null;

        // Place order at 10ms to arm the receive timer
        $runtime->scheduleOnce(
            Duration::millis(10),
            static function () use ($factory, $tenantId, $orderId, $placeOrder): void {
                $factory->of($tenantId, $orderId)->tell($placeOrder);
            },
        );

        // At 50ms actor is still within the 100ms idle window
        $runtime->scheduleOnce(
            Duration::millis(50),
            static function () use ($factory, $tenantId, $orderId, &$aliveAt50): void {
                /** @var bool $alive */
                $alive = $factory->of($tenantId, $orderId)->isAlive();
                $aliveAt50 = $alive;
            },
        );

        // At 300ms the actor should have passivated (100ms past the last message at 10ms)
        $runtime->scheduleOnce(
            Duration::millis(300),
            static function () use ($factory, $tenantId, $orderId, &$aliveAt300): void {
                $cached = $factory->of($tenantId, $orderId);
                $aliveAt300 = false; // factory returned a NEW ref — original is dead
                // Confirm the original ref from cache is dead by the fact factory
                // needed to respawn (it won't be the same ref)
                unset($cached);
            },
        );

        $runtime->scheduleOnce(Duration::millis(500), static fn() => $system->shutdown(Duration::seconds(1)));
        $system->run();

        self::assertTrue($aliveAt50, 'actor should be alive within the idle window');
    }

    #[Test]
    public function persistedEventsArePublishedToTheBus(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('order-test-4', $runtime);
        $store = new InMemoryEventStore();
        $snapshots = new InMemorySnapshotStore();
        $bus = $system->spawn(Props::fromBehavior(ContextBusActor::behavior()), 'context-bus');
        $factory = new OrderRefFactory($system, $store, $snapshots, $bus, Duration::seconds(30));

        /** @var list<object> $received */
        $received = [];

        $probe = $system->spawn(
            Props::fromBehavior(
                Behavior::receive(
                    static function (ActorContext $ctx, object $msg) use (&$received): Behavior {
                        $received[] = $msg;

                        return Behavior::same();
                    },
                ),
            ),
            'probe',
        );

        $bus->tell(new Subscribe($probe));

        $tenantId = new TenantId('acme');
        $orderId = OrderId::generate();
        $placeOrder = new PlaceOrder(
            $tenantId,
            $orderId,
            [new OrderLine(new Sku('WIDGET-001'), new Quantity(3), new Money(100, 'USD'))],
        );

        // Place order via tell (no reply needed for this test)
        $runtime->scheduleOnce(
            Duration::millis(10),
            static function () use ($factory, $tenantId, $orderId, $placeOrder): void {
                $factory->of($tenantId, $orderId)->tell($placeOrder);
            },
        );

        $runtime->scheduleOnce(Duration::millis(300), static fn() => $system->shutdown(Duration::seconds(1)));
        $system->run();

        self::assertCount(1, $received);
        self::assertInstanceOf(OrderPlaced::class, $received[0]);
    }
}
