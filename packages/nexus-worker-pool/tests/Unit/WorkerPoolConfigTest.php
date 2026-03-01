<?php

declare(strict_types=1);

namespace Monadial\Nexus\WorkerPool\Tests\Unit;

use InvalidArgumentException;
use Monadial\Nexus\WorkerPool\WorkerPoolConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(WorkerPoolConfig::class)]
final class WorkerPoolConfigTest extends TestCase
{
    #[Test]
    public function defaultSystemNamePrefixIsWorker(): void
    {
        $config = WorkerPoolConfig::withThreads(4);

        self::assertSame('worker', $config->systemNamePrefix);
    }

    #[Test]
    public function withSystemNamePrefixReturnsCopyWithNewPrefix(): void
    {
        $original = WorkerPoolConfig::withThreads(4);
        $modified = $original->withSystemNamePrefix('orders');

        self::assertSame('orders', $modified->systemNamePrefix);
        self::assertSame(4, $modified->workerCount);
        self::assertSame('worker', $original->systemNamePrefix); // immutable
    }

    #[Test]
    public function withThreadsRejectsZero(): void
    {
        $this->expectException(InvalidArgumentException::class);

        WorkerPoolConfig::withThreads(0);
    }
}
