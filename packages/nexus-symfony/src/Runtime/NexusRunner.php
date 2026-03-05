<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Runtime;

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
    private readonly SwooleHttpBridge $bridge;

    /** @param array<string, mixed> $options */
    public function __construct(private readonly HttpKernelInterface $kernel, private readonly array $options)
    {
        $this->bridge = new SwooleHttpBridge();
    }

    #[Override]
    public function run(): int
    {
        $server = new Server((string) $this->options['host'], (int) $this->options['port']);

        $server->set(['worker_num' => (int) $this->options['workers']]);

        $server->on('request', function (Request $req, Response $res): void {
            $symfonyRequest  = $this->bridge->toSymfonyRequest($req);
            $symfonyResponse = $this->kernel->handle($symfonyRequest);

            $this->bridge->sendSymfonyResponse($symfonyResponse, $res);

            if ($this->kernel instanceof TerminableInterface) {
                $this->kernel->terminate($symfonyRequest, $symfonyResponse);
            }
        });

        $server->start();

        return 0;
    }
}
