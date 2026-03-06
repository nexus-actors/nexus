<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Runtime;

use Closure;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Runtime\Swoole\SwooleEmbeddedRuntime;
use Monadial\Nexus\Symfony\Actor\ActorPropsFactory;
use Monadial\Nexus\Symfony\Http\SwooleHttpBridge;
use Monadial\Nexus\Symfony\Shutdown\GracefulShutdownHandler;
use Override;
use RuntimeException;
use Swoole\Coroutine;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Swoole\Http\Server;
use Swoole\Process;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Component\Runtime\RunnerInterface;
use Symfony\Contracts\Service\ResetInterface;

final class NexusRunner implements RunnerInterface
{
    /** @param array<string, mixed> $options */
    public function __construct(private readonly Closure $kernelFactory, private readonly array $options) {}

    #[Override]
    public function run(): int
    {
        $bridge  = new SwooleHttpBridge();
        $host    = (string) $this->options['host'];
        $port    = (int) $this->options['port'];
        $workers = (int) $this->options['workers'];

        $server = new Server($host, $port);
        $server->set([
            'enable_coroutine' => true,
            'hook_flags'       => SWOOLE_HOOK_ALL,
            'worker_num'       => $workers,
        ]);

        /** @var HttpKernelInterface|null $localKernel */
        $localKernel = null;
        /** @var ResetInterface|null $localResetter */
        $localResetter = null;

        $factory = $this->kernelFactory;

        $server->on(
            'workerStart',
            function (Server $server, int $workerId) use ($factory, &$localKernel, &$localResetter): void {
                Coroutine::create(
                    function () use ($workerId, $factory, &$localKernel, &$localResetter): void {
                        $kernel = ($factory)();

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
                            $container->set('nexus.actor_system', $system);
                            $this->bootIsolatedActors($container, $system);
                            $this->wireShutdown($container, $system);

                            if ($container->has('services_resetter')) {
                                $service = $container->get('services_resetter');
                                assert($service instanceof ResetInterface);
                                $localResetter = $service;
                            }
                        }

                        $runtime->run();
                        $localKernel = $kernel;
                    },
                );
            },
        );

        $server->on(
            'request',
            static function (SwooleRequest $req, SwooleResponse $res) use ($bridge, &$localKernel, &$localResetter): void {
                if ($localKernel === null) {
                    $res->status(503);
                    $res->end('Worker initializing');

                    return;
                }

                $kernel          = $localKernel;
                $symfonyRequest  = $bridge->toSymfonyRequest($req);
                $symfonyResponse = $kernel->handle($symfonyRequest);

                if ($kernel instanceof TerminableInterface) {
                    $kernel->terminate($symfonyRequest, $symfonyResponse);
                }

                $localResetter?->reset();
                $bridge->sendSymfonyResponse($symfonyResponse, $res);
            },
        );

        $server->start();

        return 0;
    }

    /**
     * Boot isolated actors using the compile-time service ID map.
     *
     * ActorRegistrationPass stores the map as `nexus.isolated_actors` at compile time.
     * This avoids calling findTaggedServiceIds() on the compiled container (which lacks
     * that method).
     *
     * @param \Psr\Container\ContainerInterface $container
     */
    private function bootIsolatedActors(mixed $container, ActorSystem $system): void
    {
        /** @psalm-suppress MixedMethodCall */
        if (!$container->hasParameter('nexus.isolated_actors')) {
            return;
        }

        /** @psalm-suppress MixedMethodCall */
        /** @var array<string, string> $map */
        $map = $container->getParameter('nexus.isolated_actors');

        foreach ($map as $name => $serviceId) {
            /** @psalm-suppress MixedMethodCall */
            /** @var ActorPropsFactory $factory */
            $factory = $container->get($serviceId);
            $ref     = $system->spawn($factory->create(), $name);

            /** @psalm-suppress MixedMethodCall */
            $container->set("nexus.actor_ref.{$name}", $ref);
        }
    }

    /** @param \Psr\Container\ContainerInterface $container */
    private function wireShutdown(mixed $container, ActorSystem $system): void
    {
        /** @psalm-suppress MixedMethodCall */
        if (!$container->has(GracefulShutdownHandler::class)) {
            return;
        }

        /** @psalm-suppress MixedMethodCall */
        $handler = $container->get(GracefulShutdownHandler::class);
        assert($handler instanceof GracefulShutdownHandler);

        Process::signal(SIGTERM, static function () use ($handler): void {
            $handler->shutdown();
        });
    }
}
