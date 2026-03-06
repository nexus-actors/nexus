<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\WorkerPool;

use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Symfony\Actor\WorkerSupervisorActor;
use Monadial\Nexus\WorkerPool\Swoole\WorkerPoolBootstrap;
use Monadial\Nexus\WorkerPool\WorkerNode;
use Monadial\Nexus\WorkerPool\WorkerPoolConfig;
use RuntimeException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Contracts\Service\ResetInterface;

use function Opis\Closure\serialize as opis_serialize;

/**
 * @psalm-api
 *
 * Entry point for Nexus worker pool applications backed by a Symfony kernel.
 *
 * Boots N worker threads each with a fresh kernel instance. Each thread spawns
 * a WorkerSupervisorActor ready to handle HTTP or actor messages.
 *
 * Usage from a console command:
 *   NexusSymfonyWorkerApp::run($kernel, workerCount: 4);
 */
final class NexusSymfonyWorkerApp
{
    public static function run(KernelInterface $kernel, int $workerCount): void
    {
        $kernelClass = $kernel::class;
        $environment = $kernel->getEnvironment();
        $debug       = $kernel->isDebug();

        $configure = static function (WorkerNode $node) use ($kernelClass, $environment, $debug): void {
            $k = new $kernelClass($environment, $debug);

            if (!$k instanceof HttpKernelInterface) {
                throw new RuntimeException(sprintf(
                    'Kernel class %s must implement HttpKernelInterface',
                    $kernelClass,
                ));
            }

            $k->boot();

            $resetter  = null;
            $container = $k->getContainer();

            if ($container->has('services_resetter')) {
                $service = $container->get('services_resetter');
                assert($service instanceof ResetInterface);
                $resetter = $service;
            }

            $kRef = $k;
            $rRef = $resetter;

            $node->system()->spawn(
                Props::fromFactory(static fn() => new WorkerSupervisorActor($kRef, $rRef)),
                'http-supervisor',
            );
        };

        $config = WorkerPoolConfig::withThreads($workerCount);

        WorkerPoolBootstrap::create($config)
            ->withSerializedConfigure(opis_serialize($configure))
            ->run();
    }
}
