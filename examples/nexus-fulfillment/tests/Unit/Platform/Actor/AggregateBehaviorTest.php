<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Tests\Unit\Platform\Actor;

use LogicException;
use Monadial\Nexus\Example\Fulfillment\Orders\Domain\Command\CancelOrder;
use Monadial\Nexus\Example\Fulfillment\Orders\Domain\Command\MarkStockReserved;
use Monadial\Nexus\Example\Fulfillment\Orders\Domain\Command\PlaceOrder;
use Monadial\Nexus\Example\Fulfillment\Orders\Domain\Order;
use Monadial\Nexus\Example\Fulfillment\Platform\Actor\AggregateBehavior;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\AggregateRoot;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\RejectionEvent;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderId;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\TenantId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Verifies AggregateBehavior's signature-driven handler discovery.
 * Tests use the static handler-cache via reflection to avoid building a full Behavior.
 */
#[CoversClass(AggregateBehavior::class)]
final class AggregateBehaviorTest extends TestCase
{
    protected function setUp(): void
    {
        // Clear the static discovery cache before each test to ensure isolation.
        $cache = new ReflectionProperty(AggregateBehavior::class, 'handlerCache');
        $cache->setValue(null, []);
    }

    /**
     * Order::class has exactly three command handlers discoverable by signature.
     * Verifies that place(PlaceOrder), cancel(CancelOrder), and
     * markStockReserved(MarkStockReserved) are found, and that apply(object)
     * and releaseEvents() (zero params) are excluded.
     */
    #[Test]
    public function discoverHandlersFindsExactlyThreeCommandHandlersForOrder(): void
    {
        // Trigger discovery by calling discoverHandlers via forProcess with a stub store pair.
        // We inspect the cache via reflection rather than invoking the full Behavior.
        $cacheProperty = new ReflectionProperty(AggregateBehavior::class, 'handlerCache');

        // Warm the cache by attempting discovery indirectly: instantiate Order and read the cache.
        $discoverMethod = new ReflectionMethod(AggregateBehavior::class, 'discoverHandlers');
        $handlers = $discoverMethod->invoke(null, Order::class);

        self::assertCount(3, $handlers, 'Order should expose exactly three command handlers');
        self::assertArrayHasKey(PlaceOrder::class, $handlers);
        self::assertArrayHasKey(CancelOrder::class, $handlers);
        self::assertArrayHasKey(MarkStockReserved::class, $handlers);
        self::assertSame('place', $handlers[PlaceOrder::class]);
        self::assertSame('cancel', $handlers[CancelOrder::class]);
        self::assertSame('markStockReserved', $handlers[MarkStockReserved::class]);
    }

    /**
     * Static methods are excluded from discovery.
     * Zero-param methods (releaseEvents) are excluded.
     * apply(object) is excluded because `object` is a built-in type.
     */
    #[Test]
    public function discoverHandlersExcludesApplyAndZeroParamMethods(): void
    {
        $discoverMethod = new ReflectionMethod(AggregateBehavior::class, 'discoverHandlers');
        $handlers = $discoverMethod->invoke(null, Order::class);

        self::assertArrayNotHasKey('apply', $handlers, 'apply() must not appear as a key (it is a value)');
        self::assertArrayNotHasKey('releaseEvents', $handlers, 'releaseEvents() has zero params and must be excluded');

        // apply and releaseEvents should not be handler VALUES either
        self::assertNotContains('apply', $handlers, 'apply must not be registered as a handler method');
        self::assertNotContains('releaseEvents', $handlers, 'releaseEvents must not be registered as a handler method');
    }

    /**
     * Discovery results are cached: a second call for the same class returns the
     * identical array reference without re-running reflection.
     */
    #[Test]
    public function discoverHandlersCachesResultsByClass(): void
    {
        $discoverMethod = new ReflectionMethod(AggregateBehavior::class, 'discoverHandlers');
        $first = $discoverMethod->invoke(null, Order::class);
        $second = $discoverMethod->invoke(null, Order::class);

        self::assertSame($first, $second, 'Cache must return the same array reference on repeated calls');
    }

    /**
     * When two public methods claim the same message type, discoverHandlers
     * must throw LogicException at build time.
     */
    #[Test]
    public function discoverHandlersThrowsOnAmbiguousHandlers(): void
    {
        $ambiguous = new class implements AggregateRoot {
            public function handlerA(PlaceOrder $cmd): void {}

            public function handlerB(PlaceOrder $cmd): void {}

            public function apply(object $event): void {}

            /** @return list<object> */
            public function releaseEvents(): array
            {
                return [];
            }
        };

        $discoverMethod = new ReflectionMethod(AggregateBehavior::class, 'discoverHandlers');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/Ambiguous handlers/');

        $discoverMethod->invoke(null, $ambiguous::class);
    }

    /**
     * Interfaces and abstract classes as parameter types are excluded:
     * only concrete class params are registered as handlers.
     */
    #[Test]
    public function discoverHandlersExcludesInterfaceAndAbstractParamTypes(): void
    {
        $aggregate = new class implements AggregateRoot {
            public function handleRejection(RejectionEvent $event): void {}

            public function handleConcrete(PlaceOrder $cmd): void {}

            public function apply(object $event): void {}

            /** @return list<object> */
            public function releaseEvents(): array
            {
                return [];
            }
        };

        $discoverMethod = new ReflectionMethod(AggregateBehavior::class, 'discoverHandlers');
        $handlers = $discoverMethod->invoke(null, $aggregate::class);

        self::assertCount(1, $handlers);
        self::assertArrayHasKey(PlaceOrder::class, $handlers);
    }

    private function tenant(): TenantId
    {
        return TenantId::fromString('acme');
    }

    private function orderId(): OrderId
    {
        return OrderId::generate();
    }
}
