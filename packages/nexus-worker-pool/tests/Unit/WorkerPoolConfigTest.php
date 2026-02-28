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
    public function withThreads(): void
    {
        $config = WorkerPoolConfig::withThreads(8);
        self::assertSame(8, $config->workerCount);
    }

    #[Test]
    public function throwsOnZeroWorkers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        WorkerPoolConfig::withThreads(0);
    }
}
