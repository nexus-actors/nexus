<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Runtime;

use Closure;
use Monadial\Nexus\Symfony\Http\SwooleHttpBridge;
use Override;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Component\Runtime\RunnerInterface;

final class NexusRunner implements RunnerInterface
{
    /** @param array<string, mixed> $options */
    public function __construct(private readonly Closure $kernelFactory, private readonly array $options,) {}

    #[Override]
    public function run(): int
    {
        $bridge  = new SwooleHttpBridge();
        $server  = new Server((string) $this->options['host'], (int) $this->options['port']);

        $server->set(['worker_num' => (int) $this->options['workers']]);

        $kernel   = null;
        $resetter = null;

        $server->on('workerStart', function (Server $server, int $workerId) use (&$kernel, &$resetter): void {
            $kernel = ($this->kernelFactory)();

            if (method_exists($kernel, 'boot')) {
                $kernel->boot();
            }

            if (method_exists($kernel, 'getContainer')) {
                $container = $kernel->getContainer();

                if ($container->has('services_resetter')) {
                    $resetter = $container->get('services_resetter');
                }
            }
        });

        $server->on('request', static function (Request $req, Response $res) use ($bridge, &$kernel, &$resetter): void {
            assert($kernel instanceof HttpKernelInterface);

            $symfonyRequest  = $bridge->toSymfonyRequest($req);
            $symfonyResponse = $kernel->handle($symfonyRequest);

            $bridge->sendSymfonyResponse($symfonyResponse, $res);

            if ($kernel instanceof TerminableInterface) {
                $kernel->terminate($symfonyRequest, $symfonyResponse);
            }

            if ($resetter !== null) {
                $resetter->reset();
            }
        });

        $server->start();

        return 0;
    }
}
