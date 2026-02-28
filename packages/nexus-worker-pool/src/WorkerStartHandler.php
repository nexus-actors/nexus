<?php

declare(strict_types=1);

namespace Monadial\Nexus\WorkerPool;

/**
 * @psalm-api
 *
 * Implemented by user code to set up actors when a worker thread starts.
 * The class name (string) is passed as a thread argument and instantiated fresh
 * in each thread — closures cannot cross thread boundaries in Swoole thread mode.
 */
interface WorkerStartHandler
{
    public function onWorkerStart(WorkerNode $node): void;
}
