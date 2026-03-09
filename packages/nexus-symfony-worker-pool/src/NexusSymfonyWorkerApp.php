<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\WorkerPool;

use Monadial\Nexus\Symfony\Actor\ActorPropsFactory;
use Monadial\Nexus\WorkerPool\Swoole\WorkerPoolBootstrap;
use Monadial\Nexus\WorkerPool\WorkerNode;
use Monadial\Nexus\WorkerPool\WorkerPoolConfig;
use RuntimeException;
use Symfony\Component\DependencyInjection\Container;
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
 * any #[Actor(ActorType::Shared)] actors declared in the container.
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

            $container = $k->getContainer();

            if ($container->has('services_resetter')) {
                $service = $container->get('services_resetter');
                assert($service instanceof ResetInterface);
            }

            if (!$container instanceof Container) {
                return;
            }

            if (!$container->hasParameter('nexus.shared_actors')) {
                return;
            }

            /** @var array<string, string> $sharedActors */
            $sharedActors = $container->getParameter('nexus.shared_actors');

            foreach ($sharedActors as $name => $serviceId) {
                /** @var ActorPropsFactory $propsFactory */
                $propsFactory = $container->get($serviceId);
                $node->spawn($propsFactory->create(), $name);
            }
        };

        $config = WorkerPoolConfig::withThreads($workerCount);

        WorkerPoolBootstrap::create($config)
            ->withSerializedConfigure(opis_serialize($configure))
            ->run();
    }
}
