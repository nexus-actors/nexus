<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\Threads\Tests\Unit\Server;

use Monadial\Nexus\Http\Server\Swoole\Threads\Server\SwooleThreadHttpServer;
use Monadial\Nexus\WorkerPool\ConsistentHashRing;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SwooleThreadHttpServer::class)]
final class SwooleThreadHttpServerComputeRouterNamesTest extends TestCase
{
    #[Test]
    #[DataProvider('threadCounts')]
    public function computes_one_unique_name_per_thread_owned_by_that_thread(int $threads): void
    {
        $ring = new ConsistentHashRing($threads);
        $names = SwooleThreadHttpServer::computeRouterNames($ring, $threads);

        self::assertCount($threads, $names);
        self::assertSame(array_values(array_unique($names)), array_values($names));

        for ($threadId = 0; $threadId < $threads; $threadId++) {
            self::assertArrayHasKey($threadId, $names);
            self::assertSame(
                $threadId,
                $ring->getWorker($names[$threadId]),
                "Router name for thread {$threadId} must hash to thread {$threadId}",
            );
            self::assertStringStartsWith('ws-thread-router-', $names[$threadId]);
        }
    }

    #[Test]
    public function names_are_deterministic_across_calls(): void
    {
        $ring = new ConsistentHashRing(4);

        $a = SwooleThreadHttpServer::computeRouterNames($ring, 4);
        $b = SwooleThreadHttpServer::computeRouterNames($ring, 4);

        self::assertSame($a, $b);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function threadCounts(): iterable
    {
        yield '1 thread'  => [1];
        yield '2 threads' => [2];
        yield '4 threads' => [4];
        yield '8 threads' => [8];
    }
}
