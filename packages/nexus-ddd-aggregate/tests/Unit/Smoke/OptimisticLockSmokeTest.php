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
use Monadial\Nexus\Ddd\Core\Exception\OptimisticLockException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

/**
 * End-to-end smoke for the OCC contract. Two repositories share the
 * same composite strategy (and therefore the same in-memory event
 * store). Both load the same aggregate; both record a line. The first
 * `save()` succeeds and bumps the stream to version 2; the second
 * `save()` is now stale (its expected version is 1) and the
 * versioned-append path raises `OptimisticLockException`.
 */
#[CoversNothing]
final class OptimisticLockSmokeTest extends TestCase
{
    #[Test]
    public function concurrentWriterSimulationRaisesOptimisticLockOnSecondPersist(): void
    {
        $strategy = new CompositePersistenceStrategy(
            new InMemoryEventSourcingStrategy(
                new DefaultUpcasterPipeline([]),
                new SmokeFixedClock(new DateTimeImmutable('2026-05-08T12:00:00+00:00')),
            ),
            new InMemoryStatefulStrategy(),
        );
        $repoA = new GenericAggregateRepository(Order::class, $strategy);
        $repoB = new GenericAggregateRepository(Order::class, $strategy);

        $orderId = new OrderId(new Ulid()->toBase32());
        $repoA->save(Order::placeNew($orderId, new CustomerId(new Ulid()->toBase32()), new OrderLines([])));

        $a = $repoA->find($orderId)->getUnsafe();
        $b = $repoB->find($orderId)->getUnsafe();
        self::assertNotNull($a);
        self::assertNotNull($b);

        $a->addLine(new OrderLine('apple', 1, 100));
        $repoA->save($a);

        $b->addLine(new OrderLine('banana', 2, 50));

        $this->expectException(OptimisticLockException::class);
        $repoB->save($b);
    }
}
