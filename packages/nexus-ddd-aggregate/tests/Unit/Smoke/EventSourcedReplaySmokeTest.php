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
use Monadial\Nexus\Ddd\Aggregate\Tests\Unit\Smoke\Fixtures\OrderLine;
use Monadial\Nexus\Ddd\Aggregate\Tests\Unit\Smoke\Fixtures\OrderLines;
use Monadial\Nexus\Ddd\Aggregate\Tests\Unit\Smoke\Fixtures\SmokeFixedClock;
use Monadial\Nexus\Ddd\Aggregate\Versioning\DefaultUpcasterPipeline;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionObject;
use Symfony\Component\Uid\Ulid;

/**
 * End-to-end smoke for event-sourced replay. Persists three events
 * (place + two line additions), reloads via the repository, and asserts
 * the rehydrated aggregate's state matches the post-replay state — i.e.,
 * the strategy correctly replays the stream from the event store onto a
 * blank instance.
 */
#[CoversNothing]
final class EventSourcedReplaySmokeTest extends TestCase
{
    #[Test]
    public function reloadedAggregateMatchesStateAfterMultipleRecordedEvents(): void
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
        $order = Order::placeNew($orderId, new CustomerId(new Ulid()->toBase32()), new OrderLines([]));
        $order->addLine(new OrderLine('apple', 1, 100));
        $order->addLine(new OrderLine('banana', 2, 50));

        $repository->save($order);

        $reloaded = $repository->find($orderId)->getUnsafe();

        self::assertNotNull($reloaded);
        self::assertSame(3, $reloaded->version());

        $lines = self::readLines($reloaded);
        self::assertSame(2, $lines->count());
        self::assertSame('apple', $lines->lines[0]->name);
        self::assertSame(1, $lines->lines[0]->quantity);
        self::assertSame('banana', $lines->lines[1]->name);
        self::assertSame(2, $lines->lines[1]->quantity);
    }

    private static function readLines(Order $order): OrderLines
    {
        $field = new ReflectionObject($order)->getProperty('lines');
        $value = $field->getValue($order);

        self::assertInstanceOf(OrderLines::class, $value);

        return $value;
    }
}
