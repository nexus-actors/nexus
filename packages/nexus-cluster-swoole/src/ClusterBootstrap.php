<?php
declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Swoole;

use Closure;
use Monadial\Nexus\Cluster\ClusterConfig;
use Monadial\Nexus\Cluster\ClusterNode;
use Monadial\Nexus\Cluster\ConsistentHashRing;
use Monadial\Nexus\Cluster\Serialization\ClusterSerializer;
use Monadial\Nexus\Cluster\Serialization\PhpNativeClusterSerializer;
use Monadial\Nexus\Cluster\Swoole\Directory\SwooleTableDirectory;
use Monadial\Nexus\Cluster\Swoole\Transport\UnixSocketTransport;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;
use Swoole\Coroutine;
use Swoole\Process\Pool;
use Swoole\Table;
use function Swoole\Coroutine\run;

/**
 * @psalm-api
 *
 * Entry point for the Nexus cluster.
 *
 * Creates a Swoole Process\Pool with N workers, each running an independent
 * ActorSystem. Workers communicate via Unix domain sockets with a shared
 * Swoole\Table actor directory.
 *
 * Usage:
 *     ClusterBootstrap::create(ClusterConfig::withWorkers(16))
 *         ->onWorkerStart(function (ClusterNode $node): void {
 *             $node->spawn(Props::fromBehavior($behavior), 'orders');
 *         })
 *         ->run();
 */
final class ClusterBootstrap
{
    /** @var ?Closure(ClusterNode): void */
    private ?Closure $workerCallback = null;

    private ClusterSerializer $serializer;

    private function __construct(private readonly ClusterConfig $config)
    {
        $this->serializer = new PhpNativeClusterSerializer();
    }

    public static function create(ClusterConfig $config): self
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

    public function withSerializer(ClusterSerializer $serializer): self
    {
        $this->serializer = $serializer;

        return $this;
    }

    /**
     * Start the cluster. This blocks until the Process\Pool exits.
     */
    public function run(): void
    {
        $table = SwooleTableDirectory::createTable($this->config->tableSize);

        $socketDir = $this->config->socketDir;

        if (!is_dir($socketDir)) {
            mkdir($socketDir, 0755, true);
        }

        $pool = new Pool($this->config->workerCount);

        $pool->on('WorkerStart', function (Pool $pool, int $workerId) use ($table, $socketDir): void {
            $this->startWorker($workerId, $table, $socketDir);
        });

        $pool->on('WorkerStop', static function (Pool $pool, int $workerId) use ($socketDir): void {
            $socketPath = $socketDir . "/worker-{$workerId}.sock";

            if (file_exists($socketPath)) {
                @unlink($socketPath);
            }
        });

        $pool->start();
    }

    private function startWorker(int $workerId, Table $table, string $socketDir): void
    {
        /** @psalm-suppress UnusedFunctionCall */
        run(function () use ($workerId, $table, $socketDir): void {
            $runtime = new SwooleRuntime();
            $system = ActorSystem::create("worker-{$workerId}", $runtime);
            $directory = new SwooleTableDirectory($table);
            $ring = new ConsistentHashRing($this->config->workerCount);

            $transport = new UnixSocketTransport(
                $workerId,
                $this->config->workerCount,
                $socketDir,
            );

            $node = new ClusterNode(
                $workerId,
                $system,
                $transport,
                $ring,
                $this->serializer,
                $directory,
            );

            $transport->bind();

            Coroutine::sleep(0.1);

            $transport->connectToPeers();

            $node->start();

            if ($this->workerCallback !== null) {
                ($this->workerCallback)($node);
            }

            $system->run();
        });
    }
}
