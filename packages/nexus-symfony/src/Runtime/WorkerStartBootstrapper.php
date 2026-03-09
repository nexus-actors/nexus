<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Runtime;

use Psr\Container\ContainerInterface;

/**
 * Services implementing this interface are called once per Swoole worker process
 * at startup (before any HTTP requests are served). Use it to initialize
 * worker-local resources such as connection pools.
 *
 * Tag your service with nexus.worker_start (or rely on autoconfiguration) and
 * NexusRunner will call onWorkerStart() during the workerStart coroutine.
 *
 * @psalm-api
 */
interface WorkerStartBootstrapper
{
    public function onWorkerStart(ContainerInterface $container, int $workerId): void;
}
