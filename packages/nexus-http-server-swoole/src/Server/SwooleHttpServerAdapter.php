<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\Server;

use Monadial\Nexus\Http\Server\HttpServerAdapter;
use Monadial\Nexus\Http\Server\Swoole\Bridge\SwooleRequestTranslator;
use Monadial\Nexus\Http\Server\Swoole\Bridge\SwooleResponseWriter;
use Monadial\Nexus\Runtime\Duration;
use Override;
use Psr\Http\Server\RequestHandlerInterface;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;

/**
 * @psalm-api
 *
 * Thin HttpServerAdapter that wires a Swoole\Http\Server to a PSR-15
 * RequestHandlerInterface. Use SwooleWorkerServer::run() for the
 * production entry point; this class exists primarily for the
 * HttpServerAdapterContractTest from nexus-http.
 */
final class SwooleHttpServerAdapter implements HttpServerAdapter
{
    private bool $running = false;

    public function __construct(private readonly Server $server) {}

    #[Override]
    public function serve(RequestHandlerInterface $app): void
    {
        $this->server->on('Request', static function (Request $req, Response $res) use ($app): void {
            $psr7 = SwooleRequestTranslator::toPsr7($req);
            SwooleResponseWriter::write($app->handle($psr7), $res);
        });

        $this->running = true;
        $this->server->start();
    }

    #[Override]
    public function shutdown(Duration $timeout): void
    {
        if (!$this->running) {
            return;
        }

        $this->server->shutdown();
        $this->running = false;
    }
}
