<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\SwooleThread;

use Closure;
use Monadial\Nexus\Cluster\ClusterNode;
use Swoole\Thread\Map;
use Swoole\Thread\Pool;
use Swoole\Thread\Queue;

/**
 * @psalm-api
 * @psalm-suppress UndefinedClass, UndefinedDocblockClass
 *
 * Entry point for the thread-based Nexus cluster.
 *
 * Creates a Thread\Pool with N workers, each running an independent ActorSystem.
 * Workers communicate via Thread\Queue inboxes with a shared Thread\Map directory.
 *
 * Swoole Thread classes require PHP ZTS and are not covered by swoole/ide-helper stubs.
 *
 * Usage:
 *     ThreadClusterBootstrap::create(ThreadClusterConfig::withWorkers(16))
 *         ->onWorkerStart(function (ClusterNode $node): void {
 *             $node->spawn(Props::fromBehavior($behavior), 'orders');
 *         })
 *         ->run();
 */
final class ThreadClusterBootstrap
{
    /** @var ?Closure(ClusterNode): void */
    private ?Closure $workerCallback = null;

    private function __construct(private readonly ThreadClusterConfig $config) {}

    public static function create(ThreadClusterConfig $config): self
    {
        return new self($config);
    }

    /**
     * @param callable(ClusterNode): void $callback Called once per worker with the worker's ClusterNode
     */
    public function onWorkerStart(callable $callback): self
    {
        $this->workerCallback = $callback(...);

        return $this;
    }

    /**
     * Start the cluster. This blocks until the Thread\Pool exits.
     *
     * @psalm-suppress MixedAssignment, MixedMethodCall, MissingDependency
     */
    public function run(): void
    {
        $map = new Map();

        /** @var list<Queue> $queues */
        $queues = [];

        for ($i = 0; $i < $this->config->workerCount; $i++) {
            $queues[] = new Queue();
        }

        $pool = new Pool(ThreadWorker::class, $this->config->workerCount);

        $pool->withArguments([
            'callback' => $this->workerCallback,
            'map' => $map,
            'queues' => $queues,
            'workerCount' => $this->config->workerCount,
        ]);

        $pool->withAutoloader('vendor/autoload.php');

        $pool->start();
    }
}
