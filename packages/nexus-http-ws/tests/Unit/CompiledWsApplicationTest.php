<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Ws\Tests\Unit;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Monadial\Nexus\Http\Dsl\HttpApp;
use Monadial\Nexus\Http\Ws\CompiledWsApplication;
use Monadial\Nexus\Http\Ws\Tests\Unit\WebSocket\Support\ArrayContainer;
use Monadial\Nexus\Http\Ws\Tests\Unit\WebSocket\Support\EchoHandler;
use Monadial\Nexus\Http\Ws\WebSocket\ChannelActorRegistry;
use Monadial\Nexus\Http\Ws\WebSocket\HandlerInstantiator;
use Monadial\Nexus\Http\Ws\WebSocket\InMemoryConnectionTable;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketDispatcher;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketRoute;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketRouter;
use Nyholm\Psr7\Response as Psr7Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompiledWsApplication::class)]
final class CompiledWsApplicationTest extends TestCase
{
    #[Test]
    public function has_web_socket_routes_reflects_router_state(): void
    {
        self::assertFalse($this->build([])->hasWebSocketRoutes());
        self::assertTrue($this->build([WebSocketRoute::handler('/ws/echo', EchoHandler::class)])->hasWebSocketRoutes());
    }

    #[Test]
    public function handle_delegates_to_compiled_http_app(): void
    {
        $system = ActorSystem::create('t', new TestRuntime());
        $http = HttpApp::create($system);
        $http->get('/ping', static fn() => new Psr7Response(200, [], 'pong'));
        $container = new ArrayContainer();
        $router = WebSocketRouter::build([]);
        $table = new InMemoryConnectionTable();
        $c = new CompiledWsApplication(
            $http->compile(),
            $router,
            new WebSocketDispatcher(
                $router,
                $table,
                new ChannelActorRegistry($system),
                new HandlerInstantiator($container),
                $system,
            ),
            $container,
        );

        $resp = $c->handle(new ServerRequest('GET', '/ping'));

        self::assertSame(200, $resp->getStatusCode());
    }

    /** @param list<WebSocketRoute> $routes */
    private function build(array $routes): CompiledWsApplication
    {
        $system = ActorSystem::create('t', new TestRuntime());
        $http = HttpApp::create($system);
        $container = new ArrayContainer();
        $router = WebSocketRouter::build($routes);
        $table = new InMemoryConnectionTable();

        return new CompiledWsApplication(
            $http->compile(),
            $router,
            new WebSocketDispatcher(
                $router,
                $table,
                new ChannelActorRegistry($system),
                new HandlerInstantiator($container),
                $system,
            ),
            $container,
        );
    }
}
