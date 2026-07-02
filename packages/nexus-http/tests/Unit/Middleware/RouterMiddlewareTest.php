<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Middleware;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Monadial\Nexus\Http\Actor\ActorMode;
use Monadial\Nexus\Http\Actor\ActorRegistrationEntry;
use Monadial\Nexus\Http\Actor\PerRequestActorScope;
use Monadial\Nexus\Http\Actor\ResolvedActorTable;
use Monadial\Nexus\Http\Event\RouteMatched;
use Monadial\Nexus\Http\Exception\MethodNotAllowedException;
use Monadial\Nexus\Http\Exception\PerRequestScopeDisposedException;
use Monadial\Nexus\Http\Exception\RouteNotFoundException;
use Monadial\Nexus\Http\Handler\ResolvedHandler;
use Monadial\Nexus\Http\Middleware\MiddlewarePipeline;
use Monadial\Nexus\Http\Middleware\RouterMiddleware;
use Monadial\Nexus\Http\Response\Response;
use Monadial\Nexus\Http\Routing\Dispatcher;
use Monadial\Nexus\Http\Routing\Route;
use Monadial\Nexus\Runtime\Async\Future;
use Nyholm\Psr7\ServerRequest;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class _RouterEventRecorder implements EventDispatcherInterface
{
    /** @var list<object> */
    public array $events = [];

    #[Override]
    public function dispatch(object $event): object
    {
        $this->events[] = $event;

        return $event;
    }
}

final class _TailHandler implements RequestHandlerInterface
{
    #[Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return Response::ok();
    }
}

#[CoversClass(RouterMiddleware::class)]
final class RouterMiddlewareTest extends TestCase
{
    #[Test]
    public function dispatch_404_throws_route_not_found_exception(): void
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $route = $this->makeRoute('GET', '/x');
        $router = $this->makeRouter([$route], [$this->staticHandler()], $system);

        $this->expectException(RouteNotFoundException::class);
        $router->process(new ServerRequest('GET', '/y'), new _TailHandler());
    }

    #[Test]
    public function dispatch_405_throws_method_not_allowed_exception_with_allowed_list(): void
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $route = $this->makeRoute('GET', '/x');
        $router = $this->makeRouter([$route], [$this->staticHandler()], $system);

        try {
            $router->process(new ServerRequest('POST', '/x'), new _TailHandler());
            self::fail('Expected MethodNotAllowedException');
        } catch (MethodNotAllowedException $e) {
            self::assertSame(['GET'], $e->allowed);
        }
    }

    #[Test]
    public function dispatch_attaches_path_params_as_request_attributes(): void
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $route = $this->makeRoute('GET', '/users/{id}');
        $captured = null;
        $handler = $this->captureHandler($captured);
        $router = $this->makeRouter([$route], [$handler], $system);

        $router->process(new ServerRequest('GET', '/users/42'), new _TailHandler());

        self::assertNotNull($captured);
        self::assertSame('42', $captured->getAttribute('id'));
    }

    #[Test]
    public function dispatch_attaches_per_request_actor_scope_as_attribute(): void
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $route = $this->makeRoute('GET', '/scoped');
        $captured = null;
        $handler = $this->captureHandler($captured);
        $router = $this->makeRouter([$route], [$handler], $system);

        $router->process(new ServerRequest('GET', '/scoped'), new _TailHandler());

        self::assertNotNull($captured);
        self::assertInstanceOf(PerRequestActorScope::class, $captured->getAttribute(PerRequestActorScope::class));
    }

    #[Test]
    public function dispatch_does_not_emit_route_matched_when_dispatcher_null(): void
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $route = $this->makeRoute('GET', '/x');
        $router = $this->makeRouter([$route], [$this->staticHandler()], $system, events: null);

        $response = $router->process(new ServerRequest('GET', '/x'), new _TailHandler());

        // Null dispatcher cannot record anything; reaching here without errors is the assertion.
        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function dispatch_emits_route_matched_event_when_dispatcher_present(): void
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $route = $this->makeRoute('GET', '/x');
        $events = new _RouterEventRecorder();
        $router = $this->makeRouter([$route], [$this->staticHandler()], $system, events: $events);

        $router->process(new ServerRequest('GET', '/x'), new _TailHandler());

        self::assertCount(1, $events->events);
        self::assertInstanceOf(RouteMatched::class, $events->events[0]);
    }

    #[Test]
    public function future_returning_handler_is_awaited(): void
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $route = $this->makeRoute('GET', '/async');
        $handler = new ResolvedHandler(
            static fn(): Future => Future::resolved(Response::ok()),
            returnsResponse: false,
            needsRequestScope: false,
        );
        $router = $this->makeRouter([$route], [$handler], $system);

        $response = $router->process(new ServerRequest('GET', '/async'), new _TailHandler());

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function scope_is_disposed_after_successful_handle(): void
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $route = $this->makeRoute('GET', '/scoped');

        /** @var PerRequestActorScope|null $capturedScope */
        $capturedScope = null;
        $sawSpawn = false;

        $entry = new ActorRegistrationEntry(
            'saga',
            Props::fromBehavior(Behavior::receive(static fn($ctx, $msg): Behavior => Behavior::same())),
            ActorMode::PerRequest,
            null,
            null,
        );
        $table = ResolvedActorTable::build([$entry], $system, null);

        $handler = new ResolvedHandler(
            static function (
                ServerRequestInterface $r,
                PerRequestActorScope $scope,
            ) use (&$capturedScope, &$sawSpawn): ResponseInterface {
                $scope->spawn('saga');
                $sawSpawn = $scope->hasSpawned('saga');
                $capturedScope = $scope;

                return Response::ok();
            },
            returnsResponse: true,
            needsRequestScope: true,
        );

        $router = $this->makeRouter([$route], [$handler], $system, table: $table);

        $router->process(new ServerRequest('GET', '/scoped'), new _TailHandler());

        self::assertTrue($sawSpawn, 'handler must have spawned the per-request actor');
        self::assertNotNull($capturedScope);

        // The router disposes the scope in finally — re-using it after must throw.
        $this->expectException(PerRequestScopeDisposedException::class);
        $capturedScope->spawn('saga');
    }

    private function captureHandler(?ServerRequestInterface &$captured): ResolvedHandler
    {
        return new ResolvedHandler(
            static function (ServerRequestInterface $r) use (&$captured): ResponseInterface {
                $captured = $r;

                return Response::ok();
            },
            returnsResponse: true,
            needsRequestScope: false,
        );
    }

    /**
     * @param list<Route> $routes
     * @param list<ResolvedHandler> $handlers Same order as routes.
     */
    private function makeRouter(
        array $routes,
        array $handlers,
        ActorSystem $system,
        ?ResolvedActorTable $table = null,
        ?EventDispatcherInterface $events = null,
    ): RouterMiddleware {
        $handlersByKey = [];

        foreach ($routes as $i => $route) {
            $key = $route->method . ':' . $route->path;
            $handlersByKey[$key] = $handlers[$i];
        }

        return new RouterMiddleware(
            Dispatcher::build($routes),
            $handlersByKey,
            [],
            new MiddlewarePipeline(container: null),
            $system,
            $table ?? ResolvedActorTable::build([], $system, null),
            $events,
        );
    }

    private function makeRoute(string $method, string $path): Route
    {
        return new Route($method, $path, static fn(): ResponseInterface => Response::ok(), [], null);
    }

    private function staticHandler(): ResolvedHandler
    {
        return new ResolvedHandler(
            static fn(): ResponseInterface => Response::ok(),
            returnsResponse: true,
            needsRequestScope: false,
        );
    }
}
