<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\App;

use Closure;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Http\Dsl\HttpApp;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\WebSocketContext;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\WebSocketHandler;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\WebSocketRegistry;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\WebSocketRoute;
use Monadial\Nexus\Http\Server\Swoole\WebSocket\WebSocketRouter;

/**
 * @psalm-api
 *
 * Wraps nexus-http's HttpApp and adds WebSocket route registration.
 * compile() returns a SwooleCompiledHttpApp that exposes both the HTTP
 * RequestHandler and the WebSocketRouter for the runner.
 */
final class SwooleHttpApp
{
    private readonly WebSocketRegistry $webSockets;

    private function __construct(
        private readonly HttpApp $http,
        private readonly ActorSystem $system,
    ) {
        $this->webSockets = new WebSocketRegistry();
    }

    public static function wrap(HttpApp $http, ActorSystem $system): self
    {
        return new self($http, $system);
    }

    public function compile(): SwooleCompiledHttpApp
    {
        $compiled = $this->http->compile();
        $router   = WebSocketRouter::build($this->webSockets->all());

        return new SwooleCompiledHttpApp($compiled, $router, $this->system);
    }

    public function http(): HttpApp
    {
        return $this->http;
    }

    /** @param Closure(WebSocketContext): WebSocketHandler $factory */
    public function webSocket(string $path, Closure $factory): self
    {
        $this->webSockets->add(WebSocketRoute::handler($path, $factory));

        return $this;
    }

    public function webSocketChannel(string $path, Props $props, string $keyFrom): self
    {
        $this->webSockets->add(WebSocketRoute::channel($path, $props, $keyFrom));

        return $this;
    }
}
