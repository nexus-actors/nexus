<?php

declare(strict_types=1);

namespace Monadial\Nexus\WorkerPool\Swoole;

use Monadial\Nexus\WorkerPool\WorkerNode;
use Monadial\Nexus\WorkerPool\WorkerPoolConfig;
use Monadial\Nexus\WorkerPool\WorkerStartHandler;
use Override;

/**
 * @psalm-api
 *
 * High-level entry point for worker pool applications. Mirrors NexusApp.
 *
 * Extend this class and override configure() to spawn actors per worker.
 * Call the static run() to boot the pool — identical to NexusApp::run().
 *
 * Usage:
 *
 *     final class MyApp extends WorkerPoolApp
 *     {
 *         protected function configure(WorkerNode $node): void
 *         {
 *             $node->spawn(Props::fromBehavior($orderBehavior), 'orders');
 *             $node->spawn(Props::fromFactory(fn() => new PaymentActor()), 'payments');
 *         }
 *     }
 *
 *     MyApp::run(WorkerPoolConfig::withThreads(8));
 *
 * Thread safety: configure() runs fresh in each thread. Closures used inside
 * configure() are constructed per-thread — no cross-thread serialization needed.
 */
abstract class WorkerPoolApp implements WorkerStartHandler
{
    /**
     * Boot the worker pool. Blocks until the pool exits.
     *
     * Passes static::class (a string) to the bootstrap so no closures
     * cross thread boundaries. Each thread instantiates the subclass fresh.
     */
    final public static function run(WorkerPoolConfig $config): void
    {
        WorkerPoolBootstrap::create($config)
            ->withHandler(static::class)
            ->run();
    }

    #[Override]
    final public function onWorkerStart(WorkerNode $node): void
    {
        $this->configure($node);
    }

    /**
     * Spawn actors and configure this worker. Called once per thread after
     * the WorkerNode is ready. Safe to use closures and object instantiation.
     */
    abstract protected function configure(WorkerNode $node): void;
}
