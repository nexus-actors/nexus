<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Idempotency;

use Monadial\Duration\FiniteDuration;
use Monadial\Duration\TimeUnit\TimeUnit;
use Monadial\Nexus\Ddd\Bus\Idempotency\IdempotencyKey;
use Monadial\Nexus\Ddd\Bus\Idempotency\IdempotencyStore;
use Monadial\Nexus\Ddd\Bus\Idempotency\InMemoryIdempotencyStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(InMemoryIdempotencyStore::class)]
final class InMemoryIdempotencyStoreTest extends TestCase
{
    #[Test]
    public function implementsIdempotencyStore(): void
    {
        self::assertInstanceOf(IdempotencyStore::class, new InMemoryIdempotencyStore());
    }

    #[Test]
    public function tryReserveReturnsSomeOnFirstCall(): void
    {
        $store = new InMemoryIdempotencyStore();

        $result = $store->tryReserve(stdClass::class, new IdempotencyKey('k-1'));

        self::assertTrue($result->isSome());
    }

    #[Test]
    public function doubleReserveShortCircuitsToNone(): void
    {
        $store = new InMemoryIdempotencyStore();
        $key = new IdempotencyKey('k-dup');

        self::assertTrue($store->tryReserve(stdClass::class, $key)->isSome());
        self::assertTrue($store->tryReserve(stdClass::class, $key)->isNone());
    }

    #[Test]
    public function releaseAllowsFutureReservation(): void
    {
        $store = new InMemoryIdempotencyStore();
        $key = new IdempotencyKey('k-release');

        $first = $store->tryReserve(stdClass::class, $key);
        self::assertTrue($first->isSome());

        $store->release($first->get());

        self::assertTrue($store->tryReserve(stdClass::class, $key)->isSome());
    }

    #[Test]
    public function markCompletedBlocksFutureReservation(): void
    {
        $store = new InMemoryIdempotencyStore();
        $key = new IdempotencyKey('k-committed');

        $first = $store->tryReserve(stdClass::class, $key);
        self::assertTrue($first->isSome());

        $store->markCompleted($first->get());

        self::assertTrue($store->tryReserve(stdClass::class, $key)->isNone());
    }

    #[Test]
    public function differentHandlersWithSameKeyAreIndependent(): void
    {
        $store = new InMemoryIdempotencyStore();
        $key = new IdempotencyKey('shared');

        self::assertTrue($store->tryReserve(stdClass::class, $key)->isSome());
        self::assertTrue($store->tryReserve(self::class, $key)->isSome());
    }

    #[Test]
    public function ttlReturnsThirtyDays(): void
    {
        $store = new InMemoryIdempotencyStore();

        $expected = FiniteDuration::fromTimeUnit(30, TimeUnit::Days());

        self::assertTrue($store->ttl()->equals($expected));
    }
}
