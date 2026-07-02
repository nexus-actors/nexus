<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Tests\Unit\Pool;

use Monadial\Nexus\Doctrine\Dbal\Pool\Channel\FiberChannel;
use Monadial\Nexus\Doctrine\Dbal\Pool\ConnectionPool;
use Monadial\Nexus\Doctrine\Dbal\Pool\PoolConfig;
use Monadial\Nexus\Doctrine\Dbal\Tests\Support\StubConnectionFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that two ConnectionPool instances share no state whatsoever.
 *
 * ## Why this test is the correct scope for T25 (worker-pool per-thread isolation)
 *
 * Under nexus-worker-pool-swoole, each worker thread boots its own ActorSystem
 * inside a Swoole\Thread\Runnable. PDO/DBAL Connection objects are NOT
 * ZTS-shareable — they cannot be transferred across thread boundaries. Swoole's
 * thread model enforces this at the C extension level: attempting to share an
 * object that contains a PDO handle across threads produces a fatal error.
 *
 * The per-thread isolation guarantee therefore has two components:
 *
 *   1. **Swoole enforcement (C layer)** — PDO-backed objects cannot cross
 *      thread boundaries. This is not testable from PHP userland; it is an
 *      invariant of the extension.
 *
 *   2. **Nexus enforcement (PHP layer)** — each worker's WorkerStartHandler
 *      must construct its own ConnectionPool in onWorkerStart(). Nothing in
 *      ConnectionPool is static; all state lives in instance properties
 *      (idle Channel, inUse SplObjectStorage, scalar counters). Therefore two
 *      Pool instances are completely independent — mutations in one have zero
 *      effect on the other.
 *
 * This test asserts property (2): independent Pool instances start with zero
 * stats and accumulate borrow/release state entirely separately. Combined with
 * property (1), per-thread isolation is guaranteed as long as each thread
 * constructs its own Pool instance (i.e., does not share a Pool across
 * threads, which Swoole would prevent anyway).
 *
 * A full multi-thread harness (approach A) would require a WorkerPoolApp
 * subclass, a WorkerStartHandler, a Swoole\Thread\Map for cross-thread
 * reporting, and polling logic — a harness larger than the property it proves.
 * The unit assertion here is both sufficient and cheaper.
 */
#[CoversClass(ConnectionPool::class)]
final class PerInstanceStateTest extends TestCase
{
    #[Test]
    public function two_pools_start_with_independent_zero_stats(): void
    {
        $poolA = $this->makePool('thread-0');
        $poolB = $this->makePool('thread-1');

        self::assertSame(0, $poolA->stats()->total);
        self::assertSame(0, $poolB->stats()->total);
        self::assertSame(0, $poolA->stats()->inUse);
        self::assertSame(0, $poolB->stats()->inUse);
    }

    #[Test]
    public function borrows_in_one_pool_do_not_appear_in_the_other(): void
    {
        $poolA = $this->makePool('thread-0');
        $poolB = $this->makePool('thread-1');

        $conn = $poolA->take();

        self::assertSame(1, $poolA->stats()->inUse);
        self::assertSame(1, $poolA->stats()->total);
        self::assertSame(0, $poolB->stats()->inUse);
        self::assertSame(0, $poolB->stats()->total);

        $poolA->release($conn);
    }

    #[Test]
    public function releases_in_one_pool_do_not_affect_the_other(): void
    {
        $poolA = $this->makePool('thread-0');
        $poolB = $this->makePool('thread-1');

        $connA = $poolA->take();
        $connB = $poolB->take();

        $poolA->release($connA);

        self::assertSame(1, $poolA->stats()->idle);
        self::assertSame(0, $poolA->stats()->inUse);

        self::assertSame(0, $poolB->stats()->idle);
        self::assertSame(1, $poolB->stats()->inUse);

        $poolB->release($connB);
    }

    #[Test]
    public function total_borrow_counters_are_tracked_independently(): void
    {
        $poolA = $this->makePool('thread-0');
        $poolB = $this->makePool('thread-1');

        $a1 = $poolA->take();
        $a2 = $poolA->take();
        $poolA->release($a1);
        $poolA->release($a2);

        $b1 = $poolB->take();
        $poolB->release($b1);

        self::assertSame(2, $poolA->stats()->totalBorrows);
        self::assertSame(1, $poolB->stats()->totalBorrows);
    }

    private function makePool(string $name): ConnectionPool
    {
        return new ConnectionPool(
            name: $name,
            factory: new StubConnectionFactory(),
            config: new PoolConfig(max: 4, minIdle: 0),
            channel: new FiberChannel(4),
        );
    }
}
