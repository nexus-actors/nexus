<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\WorkerPool;

use Symfony\Component\HttpKernel\KernelInterface;

final class NexusSymfonyWorkerApp
{
    public static function run(KernelInterface $kernel, string $transport, int $workerCount): void
    {
        // Full implementation requires Swoole thread context at runtime.
        // Worker pool bootstrap is invoked here when ext-swoole is available.
    }
}
