<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Tests\Unit\Smoke;

use DateTimeImmutable;
use Monadial\Nexus\Ddd\Aggregate\Repository\GenericAggregateRepository;
use Monadial\Nexus\Ddd\Aggregate\Strategy\CompositePersistenceStrategy;
use Monadial\Nexus\Ddd\Aggregate\Strategy\EventSourcing\InMemoryEventSourcingStrategy;
use Monadial\Nexus\Ddd\Aggregate\Strategy\Stateful\InMemoryStatefulStrategy;
use Monadial\Nexus\Ddd\Aggregate\Tests\Unit\Smoke\Fixtures\CustomerId;
use Monadial\Nexus\Ddd\Aggregate\Tests\Unit\Smoke\Fixtures\Order;
use Monadial\Nexus\Ddd\Aggregate\Tests\Unit\Smoke\Fixtures\OrderId;
use Monadial\Nexus\Ddd\Aggregate\Tests\Unit\Smoke\Fixtures\OrderLines;
use Monadial\Nexus\Ddd\Aggregate\Tests\Unit\Smoke\Fixtures\SmokeFixedClock;
use Monadial\Nexus\Ddd\Aggregate\Versioning\DefaultUpcasterPipeline;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionObject;
use Symfony\Component\Uid\Ulid;

/**
 * End-to-end smoke for the canonical "place an order, find it back" flow.
 * Walks the full stack with the in-memory strategy: GenericAggregateRepository
 * over CompositePersistenceStrategy over InMemoryEventSourcingStrategy.
 *
 * Asserts state via reflection so the {@see Order} fixture's API surface
 * stays free of public getters (mirrors the NoGetters constraint enforced
 * on real aggregates).
 */
#[CoversNothing]
final class PlaceOrderSmokeTest extends TestCase
{
    #[Test]
    public function placeOrderRoundTripsThroughInMemoryStrategy(): void
    {
        $repository = new GenericAggregateRepository(
            Order::class,
            new CompositePersistenceStrategy(
                new InMemoryEventSourcingStrategy(
                    new DefaultUpcasterPipeline([]),
                    new SmokeFixedClock(new DateTimeImmutable('2026-05-08T12:00:00+00:00')),
                ),
                new InMemoryStatefulStrategy(),
            ),
        );

        $orderId = new OrderId(new Ulid()->toBase32());
        $customerId = new CustomerId(new Ulid()->toBase32());
        $order = Order::placeNew($orderId, $customerId, new OrderLines([]));

        $repository->save($order);

        $reloaded = $repository->find($orderId)->getUnsafe();

        self::assertNotNull($reloaded);
        self::assertTrue($reloaded->id()->equals($orderId));
        self::assertSame(1, $reloaded->version());
        self::assertTrue(self::readCustomer($reloaded)->equals($customerId));
        self::assertSame(0, self::readLines($reloaded)->count());
    }

    private static function readCustomer(Order $order): CustomerId
    {
        $field = new ReflectionObject($order)->getProperty('customer');
        $value = $field->getValue($order);

        self::assertInstanceOf(CustomerId::class, $value);

        return $value;
    }

    private static function readLines(Order $order): OrderLines
    {
        $field = new ReflectionObject($order)->getProperty('lines');
        $value = $field->getValue($order);

        self::assertInstanceOf(OrderLines::class, $value);

        return $value;
    }
}
