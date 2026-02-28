<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\WorkerPool;

use Monadial\Nexus\WorkerPool\Swoole\DefaultWorkerStartHandler;
use Monadial\Nexus\WorkerPool\Swoole\WorkerPoolBootstrap;
use Monadial\Nexus\WorkerPool\WorkerPoolConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(WorkerPoolBootstrap::class)]
#[RequiresPhpExtension('swoole')]
final class WorkerPoolBootstrapTest extends TestCase
{
    #[Test]
    public function bootstrapCreatesConfiguredNumberOfWorkers(): void
    {
        $config = WorkerPoolConfig::withThreads(2);
        $bootstrap = WorkerPoolBootstrap::create($config);

        self::assertInstanceOf(WorkerPoolBootstrap::class, $bootstrap);
    }

    #[Test]
    public function withHandlerIsChainable(): void
    {
        $config = WorkerPoolConfig::withThreads(4);

        $bootstrap = WorkerPoolBootstrap::create($config)
            ->withHandler(DefaultWorkerStartHandler::class);

        self::assertInstanceOf(WorkerPoolBootstrap::class, $bootstrap);
    }
}
