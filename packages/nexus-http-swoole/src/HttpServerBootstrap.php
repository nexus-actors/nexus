<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Swoole;

use Closure;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\DefaultRequestCtx;
use Monadial\Nexus\Http\Error\DefaultErrorMapper;
use Monadial\Nexus\Http\Error\ErrorMapper;
use Monadial\Nexus\Http\Marshalling\MarshallerRegistry;
use Monadial\Nexus\Http\Routing\Route;
use Monadial\Nexus\Http\Routing\RouteCompiler;
use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;
use Nyholm\Psr7\Response;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Swoole\Coroutine;
use Swoole\Http\Request as SwRequest;
use Swoole\Http\Response as SwResponse;
use Swoole\Http\Server as SwServer;
use Throwable;

use const SWOOLE_BASE;

/**
 * T3 single-coroutine HTTP dev server.
 *
 * Boots a Swoole HTTP server bound to a single ActorSystem. Each incoming
 * request is dispatched in its own coroutine through the compiled DispatchTrie.
 *
 * Intended for development. Production deployments should use the threaded
 * (T1) bootstrap which scales requests across worker threads.
 */
final class HttpServerBootstrap
{
    private string $host = '127.0.0.1';

    private int $port = 8080;

    private MarshallerRegistry $registry;

    private ?Closure $onSystemReady = null;

    private LoggerInterface $logger;

    private ErrorMapper $errorMapper;

    private SwooleRequestConverter $converter;

    private SwooleResponseEmitter $emitter;

    private function __construct(public readonly Route $routes)
    {
        $this->registry    = MarshallerRegistry::withDefaults();
        $this->logger      = new NullLogger();
        $this->errorMapper = new DefaultErrorMapper();
        $this->converter   = new SwooleRequestConverter();
        $this->emitter     = new SwooleResponseEmitter();
    }

    public static function dev(Route $routes): self
    {
        return new self($routes);
    }

    public function host(string $host): self
    {
        $this->host = $host;

        return $this;
    }

    public function port(int $port): self
    {
        $this->port = $port;

        return $this;
    }

    public function logger(LoggerInterface $logger): self
    {
        $this->logger = $logger;

        return $this;
    }

    public function errorMapper(ErrorMapper $mapper): self
    {
        $this->errorMapper = $mapper;

        return $this;
    }

    /**
     * @param Closure(MarshallerRegistry): void $configure
     */
    public function marshallers(Closure $configure): self
    {
        $configure($this->registry);

        return $this;
    }

    /**
     * @param Closure(ActorSystem): void $callback
     */
    public function onSystemReady(Closure $callback): self
    {
        $this->onSystemReady = $callback;

        return $this;
    }

    public function run(): void
    {
        $runtime = new SwooleRuntime();
        $system  = ActorSystem::create('http-dev', $runtime);

        if ($this->onSystemReady !== null) {
            ($this->onSystemReady)($system);
        }

        $trie   = RouteCompiler::compile($this->routes);
        $server = new SwServer($this->host, $this->port, SWOOLE_BASE);
        $server->set(['enable_coroutine' => true]);

        $server->on('request', function (SwRequest $req, SwResponse $res) use ($trie, $system): void {
            Coroutine::create(function () use ($req, $res, $trie, $system): void {
                $psr = $this->converter->toPsrRequest($req);
                $ctx = new DefaultRequestCtx(
                    request: $psr,
                    params: [],
                    system: $system,
                    registry: $this->registry,
                    logger: $this->logger,
                );

                try {
                    $response = $trie->dispatch($ctx) ?? new Response(404);
                } catch (Throwable $e) {
                    $response = $this->errorMapper->map($e, $ctx);
                }

                $this->emitter->emit($response, $res);
            });
        });

        $server->on('start', function (): void {
            $this->logger->info('http_dev_server_ready', [
                'host' => $this->host,
                'port' => $this->port,
            ]);
        });

        $server->start();
    }
}
