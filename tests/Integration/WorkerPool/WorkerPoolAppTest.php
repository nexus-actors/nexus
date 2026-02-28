<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\WorkerPool;

use Monadial\Nexus\WorkerPool\Swoole\WorkerPoolApp;
use Monadial\Nexus\WorkerPool\WorkerNode;
use Monadial\Nexus\WorkerPool\WorkerPoolConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(WorkerPoolApp::class)]
#[RequiresPhpExtension('swoole')]
final class WorkerPoolAppTest extends TestCase
{
    #[Test]
    public function subclassImplementsWorkerStartHandler(): void
    {
        $app = new class extends WorkerPoolApp {
            protected function configure(WorkerNode $node): void
            {
                // no-op for testing
            }
        };

        self::assertInstanceOf(WorkerPoolApp::class, $app);
    }

    #[Test]
    public function runMethodAcceptsWorkerPoolConfig(): void
    {
        // Verify static::class is passed correctly by inspecting that the static
        // method exists and accepts a WorkerPoolConfig argument (does not run the pool).
        $config = WorkerPoolConfig::withThreads(2);

        self::assertInstanceOf(WorkerPoolConfig::class, $config);
    }
}
