<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Runtime;

use Closure;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Swoole\SwooleEmbeddedRuntime;
use Monadial\Nexus\Symfony\Actor\ActorPropsFactory;
use Monadial\Nexus\Symfony\Http\SwooleHttpBridge;
use Monadial\Nexus\Symfony\KernelPool\KernelPoolActor;
use Monadial\Nexus\Symfony\KernelPool\Message\HandleRequest;
use Monadial\Nexus\Symfony\KernelPool\Message\KernelResponse;
use Monadial\Nexus\Symfony\Shutdown\GracefulShutdownHandler;
use Override;
use RuntimeException;
use SplFixedArray;
use Swoole\Coroutine;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Swoole\Http\Server;
use Swoole\Process;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Runtime\RunnerInterface;

final class NexusRunner implements RunnerInterface
{
    /** @param array<string, mixed> $options */
    public function __construct(private readonly Closure $kernelFactory, private readonly array $options) {}

    #[Override]
    public function run(): int
    {
        $bridge     = new SwooleHttpBridge();
        $host       = (string) $this->options['host'];
        $port       = (int) $this->options['port'];
        $workers    = (int) $this->options['workers'];
        $poolSize   = (int) $this->options['kernel_pool_size'];
        $maxPending = (int) $this->options['kernel_pool_max_pending'];

        // Swoole thread workers re-execute the entry script using
        // $_SERVER['SCRIPT_FILENAME']. Make it absolute so threads with a
        // different CWD can still find the file.
        if (!empty($_SERVER['SCRIPT_FILENAME']) && $_SERVER['SCRIPT_FILENAME'][0] !== '/') {
            $_SERVER['SCRIPT_FILENAME'] = getcwd() . '/' . $_SERVER['SCRIPT_FILENAME'];
        }

        $server = new Server($host, $port, SWOOLE_THREAD);
        $server->set([
            'enable_coroutine' => true,
            'hook_flags'       => SWOOLE_HOOK_ALL,
            'worker_num'       => $workers,
        ]);

        /**
         * Per-worker pool refs, indexed by worker ID.
         * Only worker N writes to slot N (workerStart) and reads from slot N (request).
         * No concurrent access to the same slot — no locking needed.
         *
         * @var SplFixedArray<ActorRef<object>|null>
         */
        $poolRefs = SplFixedArray::fromArray(array_fill(0, $workers, null));

        $factory = $this->kernelFactory;

        $server->on(
            'workerStart',
            function (Server $server, int $workerId) use ($factory, $poolSize, $maxPending, $poolRefs): void {
                Coroutine::create(
                    function () use ($factory, $workerId, $poolSize, $maxPending, $poolRefs): void {
                        /** @var array<string, mixed> $env */
                        $env = $_SERVER + $_ENV;

                        $kernel = ($factory)($env);

                        if (!$kernel instanceof HttpKernelInterface) {
                            throw new RuntimeException(sprintf(
                                'Kernel factory must return HttpKernelInterface, got %s',
                                get_debug_type($kernel),
                            ));
                        }

                        if ($kernel instanceof KernelInterface) {
                            $kernel->boot();
                        }

                        $container = $kernel instanceof KernelInterface
                            ? $kernel->getContainer()
                            : null;

                        $runtime = new SwooleEmbeddedRuntime();
                        $system  = ActorSystem::create("nexus-worker-{$workerId}", $runtime);

                        if ($container !== null) {
                            $this->callWorkerStartBootstrappers($container, $workerId);
                            $container->set('nexus.actor_system', $system);
                            $container->set('nexus.runtime', $runtime);
                            $this->bootIsolatedActors($container, $system);
                            $this->wireShutdown($container, $system);
                        }

                        $poolRef             = $system->spawn(
                            KernelPoolActor::props($factory, $system, $runtime, $poolSize, $maxPending),
                            'kernel-pool',
                        );
                        $poolRefs[$workerId] = $poolRef;

                        $runtime->run();
                    },
                );
            },
        );

        $server->on(
            'request',
            static function (SwooleRequest $req, SwooleResponse $res) use ($bridge, $server, $poolRefs): void {
                $workerId = $server->getWorkerId();

                /** @var ActorRef<object>|null $poolRef */
                $poolRef = $poolRefs[$workerId];

                if ($poolRef === null) {
                    $res->status(503);
                    $res->end('Worker initializing');

                    return;
                }

                $symfonyRequest  = $bridge->toSymfonyRequest($req);
                $future          = $poolRef->ask(new HandleRequest($symfonyRequest), Duration::seconds(30));
                $kernelResponse  = $future->await();

                assert($kernelResponse instanceof KernelResponse);
                $bridge->sendSymfonyResponse($kernelResponse->response, $res);
            },
        );

        $server->start();

        return 0;
    }

    private function callWorkerStartBootstrappers(ContainerInterface $container, int $workerId): void
    {
        if (!$container instanceof Container) {
            return;
        }

        if (!$container->hasParameter('nexus.worker_start_bootstrappers')) {
            return;
        }

        /** @var list<string> $ids */
        $ids = $container->getParameter('nexus.worker_start_bootstrappers');

        foreach ($ids as $id) {
            $bootstrapper = $container->get($id);
            assert($bootstrapper instanceof WorkerStartBootstrapper);
            $bootstrapper->onWorkerStart($container, $workerId);
        }
    }

    /**
     * Boot isolated actors using the compile-time service ID map.
     *
     * ActorRegistrationPass stores the map as `nexus.isolated_actors` at compile time.
     * This avoids calling findTaggedServiceIds() on the compiled container (which lacks
     * that method).
     */
    private function bootIsolatedActors(ContainerInterface $container, ActorSystem $system): void
    {
        if (!$container instanceof Container) {
            return;
        }

        if (!$container->hasParameter('nexus.isolated_actors')) {
            return;
        }

        /** @var array<string, string> $map */
        $map = $container->getParameter('nexus.isolated_actors');

        foreach ($map as $name => $serviceId) {
            /** @var ActorPropsFactory $factory */
            $factory = $container->get($serviceId);
            $ref     = $system->spawn($factory->create(), $name);

            $container->set("nexus.actor_ref.{$name}", $ref);
        }
    }

    private function wireShutdown(ContainerInterface $container, ActorSystem $system): void
    {
        if (!$container->has(GracefulShutdownHandler::class)) {
            return;
        }

        $handler = $container->get(GracefulShutdownHandler::class);
        assert($handler instanceof GracefulShutdownHandler);

        Process::signal(SIGTERM, static function () use ($handler): void {
            $handler->shutdown();
        });
    }
}
