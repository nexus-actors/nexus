<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Middleware;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Monadial\Nexus\Http\Actor\ResolvedActorTable;
use Monadial\Nexus\Http\Handler\ResolvedHandler;
use Monadial\Nexus\Http\Middleware\MiddlewarePipeline;
use Monadial\Nexus\Http\Middleware\RouterMiddleware;
use Monadial\Nexus\Http\Response\Response;
use Monadial\Nexus\Http\Routing\Dispatcher;
use Monadial\Nexus\Http\Routing\Route;
use Nyholm\Psr7\ServerRequest;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class _StampingHandler
{
    public function __invoke(): ResponseInterface
    {
        return Response::ok();
    }
}

final class _HandlerClassAttributeTailHandler implements RequestHandlerInterface
{
    #[Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return Response::ok();
    }
}

#[CoversClass(RouterMiddleware::class)]
final class RouterMiddlewareHandlerClassAttributeTest extends TestCase
{
    #[Test]
    public function router_stamps_resolved_handler_class_on_request_when_handler_is_string(): void
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $route = new Route('GET', '/test', _StampingHandler::class, [], null);

        /** @var ServerRequestInterface|null $captured */
        $captured = null;
        $handler = new ResolvedHandler(
            static function (ServerRequestInterface $r) use (&$captured): ResponseInterface {
                $captured = $r;

                return Response::ok();
            },
            returnsResponse: true,
            needsRequestScope: false,
        );

        $router = $this->makeRouter([$route], [$handler], $system);

        $router->process(new ServerRequest('GET', '/test'), new _HandlerClassAttributeTailHandler());

        self::assertNotNull($captured);
        self::assertSame(_StampingHandler::class, $captured->getAttribute('_resolvedHandlerClass'));
    }

    #[Test]
    public function router_does_not_stamp_resolved_handler_class_when_handler_is_closure(): void
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $route = new Route('GET', '/test', static fn(): ResponseInterface => Response::ok(), [], null);

        /** @var ServerRequestInterface|null $captured */
        $captured = null;
        $handler = new ResolvedHandler(
            static function (ServerRequestInterface $r) use (&$captured): ResponseInterface {
                $captured = $r;

                return Response::ok();
            },
            returnsResponse: true,
            needsRequestScope: false,
        );

        $router = $this->makeRouter([$route], [$handler], $system);

        $router->process(new ServerRequest('GET', '/test'), new _HandlerClassAttributeTailHandler());

        self::assertNotNull($captured);
        self::assertNull($captured->getAttribute('_resolvedHandlerClass'));
    }

    /**
     * @param list<Route> $routes
     * @param list<ResolvedHandler> $handlers Same order as routes.
     */
    private function makeRouter(array $routes, array $handlers, ActorSystem $system): RouterMiddleware
    {
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
            ResolvedActorTable::build([], $system, null),
            null,
        );
    }
}
