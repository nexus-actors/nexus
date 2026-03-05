<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Runtime;

use Closure;
use Monadial\Nexus\Symfony\Http\SwooleHttpBridge;
use Override;
use RuntimeException;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Component\Runtime\RunnerInterface;
use Symfony\Contracts\Service\ResetInterface;

final class NexusRunner implements RunnerInterface
{
    /** @param array<string, mixed> $options */
    public function __construct(private readonly Closure $kernelFactory, private readonly array $options,) {
    }

    #[Override]
    public function run(): int
    {
        $bridge = new SwooleHttpBridge();
        $server = new Server((string) $this->options['host'], (int) $this->options['port']);

        $server->set(['worker_num' => (int) $this->options['workers']]);

        $kernel   = null;
        $resetter = null;

        $server->on('workerStart', function (Server $_server, int $_workerId) use (&$kernel, &$resetter): void {
            $result = ($this->kernelFactory)();

            if (!$result instanceof HttpKernelInterface) {
                throw new RuntimeException(
                    sprintf('Kernel factory must return an HttpKernelInterface, got %s', get_debug_type($result)),
                );
            }

            $kernel = $result;

            if ($kernel instanceof KernelInterface) {
                $kernel->boot();
                $container = $kernel->getContainer();

                if ($container->has('services_resetter')) {
                    $service = $container->get('services_resetter');
                    assert($service instanceof ResetInterface);
                    $resetter = $service;
                }
            }
        });

        $server->on('request', static function (Request $req, Response $res) use ($bridge, &$kernel, &$resetter): void {
            if (!$kernel instanceof HttpKernelInterface) {
                $res->status(503);
                $res->end('Kernel not initialised');

                return;
            }

            $symfonyRequest  = $bridge->toSymfonyRequest($req);
            $symfonyResponse = $kernel->handle($symfonyRequest);

            $bridge->sendSymfonyResponse($symfonyResponse, $res);

            if ($kernel instanceof TerminableInterface) {
                $kernel->terminate($symfonyRequest, $symfonyResponse);
            }

            if ($resetter instanceof ResetInterface) {
                $resetter->reset();
            }
        });

        $server->start();

        return 0;
    }
}
