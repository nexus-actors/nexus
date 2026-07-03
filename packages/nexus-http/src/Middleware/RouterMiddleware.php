<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Middleware;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\Actor\PerRequestActorScope;
use Monadial\Nexus\Http\Actor\ResolvedActorTable;
use Monadial\Nexus\Http\Event\RouteMatched;
use Monadial\Nexus\Http\Exception\MethodNotAllowedException;
use Monadial\Nexus\Http\Exception\RouteNotFoundException;
use Monadial\Nexus\Http\Handler\ResolvedHandler;
use Monadial\Nexus\Http\Routing\Dispatcher;
use Monadial\Nexus\Http\Routing\DispatchResult;
use Monadial\Nexus\Http\Routing\Route;
use Monadial\Nexus\Runtime\Async\Future;
use Override;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\Uid\Ulid;

use function is_string;

/**
 * @psalm-api
 *
 * Innermost middleware. Dispatches the request, builds the per-request scope
 * lazily (only if the route needs one), runs route-level middlewares, calls
 * the handler, awaits Future if applicable, and disposes the scope in finally.
 */
final readonly class RouterMiddleware implements MiddlewareInterface
{
    /**
     * @param array<string, ResolvedHandler> $handlersByRouteKey key = "METHOD:path"
     * @param array<string, list<MiddlewareInterface>> $routeMiddlewaresByKey
     */
    public function __construct(
        private Dispatcher $dispatcher,
        private array $handlersByRouteKey,
        private array $routeMiddlewaresByKey,
        private MiddlewarePipeline $pipeline,
        private ActorSystem $system,
        private ResolvedActorTable $actors,
        private ?EventDispatcherInterface $events,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $result = $this->dispatcher->dispatch($request->getMethod(), $request->getUri()->getPath());

        match ($result->status) {
            DispatchResult::NOT_FOUND          => throw new RouteNotFoundException(
                $request->getMethod(),
                $request->getUri()->getPath(),
            ),
            DispatchResult::METHOD_NOT_ALLOWED => throw new MethodNotAllowedException(
                $request->getMethod(),
                $request->getUri()->getPath(),
                $result->allowedMethods,
            ),
            DispatchResult::FOUND              => null,
        };

        /** @var Route $route */
        $route = $result->route;
        $key = $this->routeKey($route);
        $resolved = $this->handlersByRouteKey[$key];

        foreach ($result->pathParams as $name => $value) {
            $request = $request->withAttribute($name, $value);
        }

        // Marker that the router has run. Route-level middleware (which executes
        // below, after this point) can rely on it; middleware registered before
        // the router will not see it. Used by AuthorizationMiddleware to detect
        // global misregistration and fail closed instead of open.
        $request = $request->withAttribute('_nexus.routed', true);

        if (is_string($route->handler)) {
            $request = $request->withAttribute('_resolvedHandlerClass', $route->handler);
        }

        $externalRequestId = $request->getHeaderLine('X-Request-Id');
        $internalId = (string) new Ulid();
        $requestId = $externalRequestId === ''
            ? $internalId
            : "{$externalRequestId}-{$internalId}";

        $scope = new PerRequestActorScope($this->system, $this->actors->perRequestEntries(), $requestId);
        $request = $request->withAttribute(PerRequestActorScope::class, $scope);

        if ($this->events !== null) {
            $this->events->dispatch(new RouteMatched($request, $route, $result->pathParams));
        }

        try {
            $tail =
                /** @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement */
                static function (ServerRequestInterface $r) use ($resolved, $scope, $result): ResponseInterface {
                    $out = ($resolved->invoke)($r, $scope, $result->pathParams);

                    return $out instanceof Future
                        ? $out->await()
                        : $out;
                };

            return $this->pipeline->process(
                $this->routeMiddlewaresByKey[$key] ?? [],
                $request,
                $tail,
            );
        } finally {
            $scope->dispose();
        }
    }

    private function routeKey(Route $route): string
    {
        return $route->method . ':' . $route->path;
    }
}
