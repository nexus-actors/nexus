<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Runtime;

use Closure;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Runtime\Swoole\SwooleEmbeddedRuntime;
use Monadial\Nexus\Symfony\Actor\ActorPropsFactory;
use Monadial\Nexus\Symfony\Actor\WorkerSupervisorActor;
use Monadial\Nexus\Symfony\Http\SwooleHttpBridge;
use Monadial\Nexus\Symfony\Message\HandleHttpRequest;
use Override;
use RuntimeException;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Swoole\Http\Server;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Runtime\RunnerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Swoole HTTP server runner with actor-per-request pattern.
 *
 * Each worker thread/process boots a fresh Symfony kernel, an ActorSystem
 * (SwooleEmbeddedRuntime), and a WorkerSupervisorActor. For each HTTP request,
 * the supervisor spawns an ephemeral RequestActor that handles the request
 * and pushes the Symfony Response back via a per-request Channel.
 *
 * Isolated actors registered via #[Actor(ActorType::Isolated)] are booted
 * per worker and their refs are populated in the container.
 */
final class NexusRunner implements RunnerInterface
{
    private const int REQUEST_TIMEOUT_SECONDS = 30;

    /** @param array<string, mixed> $options */
    public function __construct(private readonly Closure $kernelFactory, private readonly array $options) {}

    #[Override]
    public function run(): int
    {
        $bridge  = new SwooleHttpBridge();
        $factory = $this->kernelFactory;
        $server  = new Server((string) $this->options['host'], (int) $this->options['port']);

        $server->set([
            'enable_coroutine' => true,
            'hook_flags'       => SWOOLE_HOOK_ALL,
            'worker_num'       => (int) $this->options['workers'],
        ]);

        /** @var ActorRef<object>|null $localSupervisor */
        $localSupervisor = null;

        $server->on(
            'workerStart',
            function (Server $server, int $workerId) use ($factory, &$localSupervisor): void {
                Coroutine::create(
                    function () use ($workerId, $factory, &$localSupervisor): void {
                        $result = ($factory)();

                        if (!$result instanceof HttpKernelInterface) {
                            throw new RuntimeException(sprintf(
                                'Kernel factory must return HttpKernelInterface, got %s',
                                get_debug_type($result),
                            ));
                        }

                        $resetter = null;

                        if ($result instanceof KernelInterface) {
                            $result->boot();
                            $container = $result->getContainer();

                            if ($container->has('services_resetter')) {
                                $service = $container->get('services_resetter');
                                assert($service instanceof ResetInterface);
                                $resetter = $service;
                            }
                        }

                        $kernel  = $result;
                        $runtime = new SwooleEmbeddedRuntime();
                        $system  = ActorSystem::create("nexus-worker-{$workerId}", $runtime);

                        $kernelRef   = $kernel;
                        $resetterRef = $resetter;

                        $supervisor = $system->spawn(
                            Props::fromFactory(
                                static fn() => new WorkerSupervisorActor($kernelRef, $resetterRef),
                            ),
                            'http-supervisor',
                        );

                        $this->bootIsolatedActors($kernel, $system);

                        $runtime->run();

                        $localSupervisor = $supervisor;
                    },
                );
            },
        );

        $server->on(
            'request',
            static function (SwooleRequest $req, SwooleResponse $res) use ($bridge, &$localSupervisor): void {
                if ($localSupervisor === null) {
                    $res->status(503);
                    $res->end('Worker not ready');

                    return;
                }

                $symfonyRequest  = $bridge->toSymfonyRequest($req);
                $responseChannel = new Channel(1);

                $localSupervisor->tell(new HandleHttpRequest($symfonyRequest, $responseChannel));

                /** @var Response|false $response */
                $response = $responseChannel->pop((float) self::REQUEST_TIMEOUT_SECONDS);

                if ($response === false) {
                    $res->status(503);
                    $res->end('Request timeout');

                    return;
                }

                $bridge->sendSymfonyResponse($response, $res);
            },
        );

        $server->start();

        return 0;
    }

    private function bootIsolatedActors(HttpKernelInterface $kernel, ActorSystem $system): void
    {
        if (!$kernel instanceof KernelInterface) {
            return;
        }

        $container = $kernel->getContainer();

        /** @psalm-suppress MixedMethodCall */
        foreach ($container->findTaggedServiceIds('nexus.isolated_actor') as $serviceId => $tags) {
            /** @var ActorPropsFactory $factory */
            $factory = $container->get($serviceId);
            $name    = (string) ($tags[0]['name'] ?? $serviceId);

            $ref = $system->spawn($factory->create(), $name);
            $container->set("nexus.actor_ref.{$name}", $ref);
        }
    }
}
