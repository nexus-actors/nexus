<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Dsl;

use Closure;
use LogicException;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Http\Actor\ActorMode;
use Monadial\Nexus\Http\Actor\ActorRegistry;
use Monadial\Nexus\Http\Actor\PoolSingletonSpawner;
use Monadial\Nexus\Http\Actor\ResolvedActorTable;
use Monadial\Nexus\Http\App\CompiledHttpApp;
use Monadial\Nexus\Http\App\ErrorMode;
use Monadial\Nexus\Http\Cache\RouteCachePersister;
use Monadial\Nexus\Http\Discovery\RouteDiscoverer;
use Monadial\Nexus\Http\Exception\DefaultMappers;
use Monadial\Nexus\Http\Exception\ExceptionMapperRegistry;
use Monadial\Nexus\Http\Exception\HttpAppAlreadyCompiledException;
use Monadial\Nexus\Http\Handler\HandlerResolver;
use Monadial\Nexus\Http\Handler\Resolver\Builtin\ContainerFallbackResolver;
use Monadial\Nexus\Http\Handler\Resolver\Builtin\FromActorResolver;
use Monadial\Nexus\Http\Handler\Resolver\Builtin\FromBodyResolver;
use Monadial\Nexus\Http\Handler\Resolver\Builtin\FromServiceResolver;
use Monadial\Nexus\Http\Handler\Resolver\Builtin\PathParamResolver;
use Monadial\Nexus\Http\Handler\Resolver\Builtin\PerRequestScopeResolver;
use Monadial\Nexus\Http\Handler\Resolver\Builtin\ServerRequestResolver;
use Monadial\Nexus\Http\Handler\Resolver\ParamResolver;
use Monadial\Nexus\Http\Handler\Resolver\ParamResolverRegistry;
use Monadial\Nexus\Http\Middleware\ExceptionHandlerMiddleware;
use Monadial\Nexus\Http\Middleware\MiddlewareInvoker;
use Monadial\Nexus\Http\Middleware\MiddlewarePipeline;
use Monadial\Nexus\Http\Middleware\MiddlewareResolver;
use Monadial\Nexus\Http\Middleware\RouterMiddleware;
use Monadial\Nexus\Http\Routing\Dispatcher;
use Monadial\Nexus\Http\Routing\RouteCollection;
use Monadial\Nexus\Http\Routing\RouteSummary;
use Monadial\Nexus\Serialization\MessageSerializer;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Throwable;

/**
 * Fluent DSL entry point for building a Nexus HTTP application.
 *
 * {@see HttpApp} is mutable during construction: you register routes, actors,
 * middleware, and exception mappers by chaining method calls. The terminal
 * {@see HttpApp::compile()} operation freezes the DSL state into an immutable
 * {@see CompiledHttpApp} that implements PSR-15 {@see RequestHandlerInterface}
 * and is ready to be handed to a Swoole or Fiber HTTP server adapter.
 *
 * `HttpApp` itself does NOT implement `RequestHandlerInterface`. The separation
 * of "building" from "serving" keeps the hot-path handler allocation cost in
 * the server adapter rather than here. The first {@see compile()} call freezes
 * the DSL: repeated compiles are idempotent (reusing the frozen state and the
 * live actor table), and any further mutation throws
 * {@see HttpAppAlreadyCompiledException}.
 *
 * Example — minimal JSON API:
 * ```php
 * $app = HttpApp::create($system)
 *     ->get('/users/{id}', UserHandler::class)
 *     ->middleware(AuthenticationMiddleware::class);
 *
 * $app->post('/users', UserHandler::class)
 *     ->name('user.create');
 *
 * $compiled = $app->compile();
 * // Hand $compiled to SwooleHttpServer or similar adapter.
 * ```
 *
 * Example — actor-backed route (inject the actor via #[FromActor]):
 * ```php
 * $app = HttpApp::create($system)
 *     ->actor('orders', Props::fromBehavior($orderBehavior))
 *     ->post('/orders', static function (
 *         ServerRequestInterface $request,
 *         #[FromActor('orders')] ActorRef $orders,
 *     ): ResponseInterface {
 *         $orders->tell(new PlaceOrder(...));
 *
 *         return Response::accepted();
 *     });
 * ```
 *
 * @see CompiledHttpApp     The immutable PSR-15 handler produced by compile()
 * @see RouteBuilder        Fluent per-route configuration (middleware, name)
 * @see WsApplication       Decorator that adds WebSocket DSL on top of HttpApp
 *
 * @psalm-api
 */
final class HttpApp
{
    private readonly ActorRegistry $registry;

    private readonly RouteCollection $routes;

    private readonly ExceptionMapperRegistry $mappers;

    /** @var list<RouteBuilder> */
    private array $pendingBuilders = [];

    /** @var list<string> */
    private array $discoveryDirs = [];

    /** @var list<string|MiddlewareInterface> */
    private array $globalMiddleware = [];

    /** @var list<Closure(ExceptionMapperRegistry): void> */
    private array $userExceptionRegistrations = [];

    /** @var list<ParamResolver> */
    private array $paramResolvers = [];

    private ErrorMode $errorMode = ErrorMode::Production;

    private bool $useDefaultExceptionHandler = true;

    private ?CacheInterface $routeCache = null;

    private string $routeCacheKey = 'nexus.http.routes';

    private ?PoolSingletonSpawner $poolSingletonSpawner = null;

    private ?MessageSerializer $messageSerializer = null;

    private bool $compiled = false;

    private ?ResolvedActorTable $actorTable = null;

    private function __construct(
        private readonly ActorSystem $system,
        private readonly ?ContainerInterface $container,
        private readonly ?EventDispatcherInterface $events,
        private readonly ?LoggerInterface $logger,
    ) {
        $this->registry = new ActorRegistry();
        $this->routes = new RouteCollection();
        $this->mappers = new ExceptionMapperRegistry();
    }

    /**
     * Create a new HttpApp DSL instance tied to the given actor system.
     *
     * @param ActorSystem                $system    The running actor system (used to spawn actor-backed routes).
     * @param ContainerInterface|null    $container PSR-11 container for resolving handler and middleware class names.
     * @param EventDispatcherInterface|null $events PSR-14 dispatcher; emits {@see RequestStarted} and {@see RequestCompleted}.
     * @param LoggerInterface|null       $logger    PSR-3 logger forwarded to the exception-handler middleware.
     */
    public static function create(
        ActorSystem $system,
        ?ContainerInterface $container = null,
        ?EventDispatcherInterface $events = null,
        ?LoggerInterface $logger = null,
    ): self {
        return new self($system, $container, $events, $logger);
    }

    // ─── Actors ────────────────────────────────────────────────────

    /**
     * Register a long-lived actor shared across all requests on this worker.
     *
     * The actor is spawned once per worker process when {@see compile()} is
     * called and remains alive for the lifetime of the worker. Handlers and
     * middleware receive the actor by declaring a parameter attributed with
     * `#[FromActor('name')]`.
     *
     * @param string $name  Unique actor name within this app.
     * @param Props  $props Actor spawn configuration.
     * @return ActorRegistration Fluent configuration (e.g. set actor mode).
     */
    public function actor(string $name, Props $props): ActorRegistration
    {
        $this->assertNotCompiled('actor()');

        return $this->registry->register($name, $props, ActorMode::WorkerLocal);
    }

    // ─── Compile ───────────────────────────────────────────────────

    /**
     * Evict all entries from the route cache configured via {@see withRouteCache()}.
     *
     * Useful during deployment or when routes change at runtime. A no-op if no
     * cache has been configured.
     */
    public function clearRouteCache(): void
    {
        if ($this->routeCache !== null) {
            new RouteCachePersister($this->routeCache, $this->routeCacheKey)->clear();
        }
    }

    /**
     * Freeze the DSL state into an immutable, ready-to-serve CompiledHttpApp.
     *
     * The first call is terminal: routes, actors, middleware, and configuration
     * are frozen, and worker-local/pool-singleton actors are spawned exactly
     * once. Repeated calls are idempotent — they reuse the frozen state and the
     * live actor table and return an equivalent CompiledHttpApp. Mutating the
     * DSL after compile() throws {@see HttpAppAlreadyCompiledException}.
     */
    public function compile(): CompiledHttpApp
    {
        // 1. First-time-only side effects: attribute discovery + pending-builder
        // promotion contribute to $this->routes exactly once per HttpApp instance.
        // Subsequent compile() calls reuse the snapshotted route collection so
        // repeated compiles do not duplicate routes.
        if (!$this->compiled) {
            $discoverer = new RouteDiscoverer();

            foreach ($this->discoveryDirs as $dir) {
                foreach ($discoverer->discover($dir) as $route) {
                    $this->routes->add($route);
                }
            }

            foreach ($this->pendingBuilders as $builder) {
                $this->routes->add($builder->build());
            }

            $this->pendingBuilders = [];
            $this->compiled = true;
        }

        // 2. Route cache hit-or-fill — closure routes are skipped from the
        // cached payload and re-added from the live collection on hit.
        $routeList = $this->routes->all();
        $persister = null;
        $cacheHit = false;

        if ($this->routeCache !== null) {
            $persister = new RouteCachePersister($this->routeCache, $this->routeCacheKey);
            $cached = $persister->load();

            if ($cached !== null) {
                $cacheHit = true;
                $closureRoutes = [];

                foreach ($routeList as $route) {
                    if (!is_string($route->handler)) {
                        $closureRoutes[] = $route;
                    }
                }

                $routeList = [...$cached, ...$closureRoutes];
            }
        }

        // 2b. Resolve actor table exactly once per HttpApp instance. Worker-local
        // and pool-singleton actors are long-lived per-worker singletons; building
        // the table again would respawn the same names and throw
        // ActorNameExistsException, so subsequent compiles reuse the live table.
        $table = $this->actorTable ??= ResolvedActorTable::build(
            $this->registry->freeze(),
            $this->system,
            $this->poolSingletonSpawner,
        );

        // 3. Resolve handlers per route. If this throws (e.g. UnknownActorException),
        // we must NOT have written to the route cache yet — see step 3a.
        $resolver = new HandlerResolver($table, $this->container, $this->messageSerializer, $this->buildRegistry());
        $middlewareResolver = new MiddlewareResolver($this->container);
        $handlersByKey = [];
        $routeMwsByKey = [];

        foreach ($routeList as $route) {
            $key = $route->method . ':' . $route->path;
            $handlersByKey[$key] = $resolver->resolve($route->handler);
            $routeMwsByKey[$key] = array_map(
                static fn(string|MiddlewareInterface $mw): MiddlewareInterface => $mw instanceof MiddlewareInterface
                    ? $mw
                    : $middlewareResolver->resolve($mw),
                $route->middleware,
            );
        }

        // 3a. Persist route cache only AFTER handler resolution succeeds, so a
        // failed compile (e.g. unknown actor reference) never leaves stale routes
        // in the cache.
        if ($persister !== null && !$cacheHit) {
            $persister->save($routeList);
        }

        // 4. Mappers — defaults first so user overrides win.
        $mappers = clone $this->mappers;

        if ($this->useDefaultExceptionHandler) {
            DefaultMappers::registerInto($mappers, $this->errorMode);
        }

        foreach ($this->userExceptionRegistrations as $apply) {
            $apply($mappers);
        }

        // 5. Build dispatcher + RouterMiddleware.
        $pipeline = new MiddlewarePipeline($this->container);
        $router = new RouterMiddleware(
            Dispatcher::build($routeList),
            $handlersByKey,
            $routeMwsByKey,
            $pipeline,
            $this->system,
            $table,
            $this->events,
        );

        // 6. Compile the full middleware stack into ONE RequestHandlerInterface.
        $stack = [];

        if ($this->useDefaultExceptionHandler) {
            $stack[] = new ExceptionHandlerMiddleware($mappers, $this->logger);
        }

        foreach ($this->globalMiddleware as $mw) {
            $stack[] = $mw instanceof MiddlewareInterface
                ? $mw
                : $middlewareResolver->resolve($mw);
        }

        $stack[] = $router;

        $tail = static function (): ResponseInterface {
            throw new LogicException('RouterMiddleware did not produce a response');
        };

        return new CompiledHttpApp(new MiddlewareInvoker($stack, $tail), $this->events);
    }

    /**
     * Register a DELETE route.
     *
     * @param string         $path    URI path (may contain `{param}` placeholders).
     * @param string|Closure $handler Handler class name or inline closure.
     * @return RouteBuilder Fluent builder for per-route middleware and naming.
     */
    public function delete(string $path, string|Closure $handler): RouteBuilder
    {
        return $this->registerRoute('DELETE', $path, $handler);
    }

    /**
     * Scan a directory for classes annotated with route attributes and register them automatically.
     *
     * Discovered routes are merged into the route collection on the first
     * {@see compile()} call. May be called multiple times to add more directories.
     *
     * @param string $directory Absolute path to scan recursively for PHP classes.
     */
    public function discover(string $directory): self
    {
        $this->assertNotCompiled('discover()');

        $this->discoveryDirs[] = $directory;

        return $this;
    }

    /**
     * Set the error-reporting mode for the built-in exception-handler middleware.
     *
     * {@see ErrorMode::Production} (default) returns opaque 500 responses.
     * {@see ErrorMode::Development} includes stack traces in the response body.
     *
     * @param ErrorMode $mode The desired error verbosity mode.
     */
    public function errorMode(ErrorMode $mode): self
    {
        $this->assertNotCompiled('errorMode()');

        $this->errorMode = $mode;

        return $this;
    }

    // ─── Routing ───────────────────────────────────────────────────

    /**
     * Register a GET route.
     *
     * @param string         $path    URI path (may contain `{param}` placeholders).
     * @param string|Closure $handler Handler class name or inline closure.
     * @return RouteBuilder Fluent builder for per-route middleware and naming.
     */
    public function get(string $path, string|Closure $handler): RouteBuilder
    {
        return $this->registerRoute('GET', $path, $handler);
    }

    /**
     * Register a group of routes sharing a common path prefix.
     *
     * The `$register` closure receives a {@see RouteGroup} instance on which
     * you call the same `get()`, `post()`, etc. methods. All routes defined
     * inside the closure are committed to this `HttpApp` when `group()` returns.
     *
     * @param string                    $prefix   Common URI prefix for all routes in the group.
     * @param Closure(RouteGroup): void $register Closure that defines routes on the group.
     */
    public function group(string $prefix, Closure $register): RouteGroup
    {
        $this->assertNotCompiled('group()');

        $group = new RouteGroup($prefix);
        $register($group);

        foreach ($group->commit() as $route) {
            $this->routes->add($route);
        }

        return $group;
    }

    // ─── Middleware ────────────────────────────────────────────────

    /**
     * Append a PSR-15 middleware to the global stack applied to every request.
     *
     * Middleware is applied in registration order, before per-route middleware.
     * Pass a class name string when using a PSR-11 container, or a concrete
     * `MiddlewareInterface` instance for inline middleware.
     *
     * @param string|MiddlewareInterface $middleware Class name or middleware instance.
     */
    public function middleware(string|MiddlewareInterface $middleware): self
    {
        $this->assertNotCompiled('middleware()');

        $this->globalMiddleware[] = $middleware;

        return $this;
    }

    // ─── Errors ────────────────────────────────────────────────────

    /**
     * Register a custom exception-to-response mapper for a specific exception type.
     *
     * User-registered mappers take precedence over the built-in default mappers.
     * If the built-in exception handler is disabled via
     * {@see withoutDefaultExceptionHandler()}, this method has no effect unless
     * you add your own `ExceptionHandlerMiddleware`.
     *
     * @template TException of Throwable
     * @param class-string<TException>                                        $exceptionClass Exception class to handle.
     * @param Closure(TException, ServerRequestInterface): ResponseInterface  $mapper         Converts the exception to an HTTP response.
     */
    public function onException(string $exceptionClass, Closure $mapper): self
    {
        $this->assertNotCompiled('onException()');

        $this->userExceptionRegistrations[] = static function (ExceptionMapperRegistry $r) use ($exceptionClass, $mapper): void {
            $r->register($exceptionClass, $mapper);
        };

        return $this;
    }

    /**
     * Register a custom handler-parameter resolver.
     *
     * Custom resolvers are appended after all built-in resolvers by default.
     * Pass `true` for `$override` to prepend the resolver so it takes priority
     * over the built-in ones.
     *
     * @param ParamResolver $resolver  The custom resolver to add.
     * @param bool          $override  When `true`, prepend instead of append.
     */
    public function paramResolver(ParamResolver $resolver, bool $override = false): self
    {
        $this->assertNotCompiled('paramResolver()');

        if ($override) {
            array_unshift($this->paramResolvers, $resolver);
        } else {
            $this->paramResolvers[] = $resolver;
        }

        return $this;
    }

    /**
     * Register a PATCH route.
     *
     * @param string         $path    URI path (may contain `{param}` placeholders).
     * @param string|Closure $handler Handler class name or inline closure.
     * @return RouteBuilder Fluent builder for per-route middleware and naming.
     */
    public function patch(string $path, string|Closure $handler): RouteBuilder
    {
        return $this->registerRoute('PATCH', $path, $handler);
    }

    /**
     * Register a per-request actor that is spawned fresh for each incoming request.
     *
     * The actor is stopped automatically after the request handler returns.
     * Useful for request-scoped stateful logic (e.g. a saga or command handler).
     *
     * @param string $name  Unique actor name within this app.
     * @param Props  $props Actor spawn configuration.
     * @return ActorRegistration Fluent configuration.
     */
    public function perRequestActor(string $name, Props $props): ActorRegistration
    {
        $this->assertNotCompiled('perRequestActor()');

        return $this->registry->register($name, $props, ActorMode::PerRequest);
    }

    /**
     * Register a POST route.
     *
     * @param string         $path    URI path (may contain `{param}` placeholders).
     * @param string|Closure $handler Handler class name or inline closure.
     * @return RouteBuilder Fluent builder for per-route middleware and naming.
     */
    public function post(string $path, string|Closure $handler): RouteBuilder
    {
        return $this->registerRoute('POST', $path, $handler);
    }

    /**
     * Register a PUT route.
     *
     * @param string         $path    URI path (may contain `{param}` placeholders).
     * @param string|Closure $handler Handler class name or inline closure.
     * @return RouteBuilder Fluent builder for per-route middleware and naming.
     */
    public function put(string $path, string|Closure $handler): RouteBuilder
    {
        return $this->registerRoute('PUT', $path, $handler);
    }

    // ─── Capability flags ──────────────────────────────────────────

    /**
     * Return `true` if any registered actor uses the `PoolSingleton` mode.
     *
     * Server adapters inspect this flag to decide whether to boot a shared
     * singleton coordinator before starting request workers.
     */
    public function requiresPoolSingleton(): bool
    {
        foreach ($this->registry->freeze() as $entry) {
            if ($entry->mode === ActorMode::PoolSingleton) {
                return true;
            }
        }

        return false;
    }

    /**
     * Attach a message serializer used when actor-backed routes need to encode
     * request payloads as actor messages across worker boundaries.
     *
     * @param MessageSerializer $serializer The serializer to use.
     */
    public function withMessageSerializer(MessageSerializer $serializer): self
    {
        $this->assertNotCompiled('withMessageSerializer()');

        $this->messageSerializer = $serializer;

        return $this;
    }

    /**
     * Supply the spawner used to boot `PoolSingleton` actors.
     *
     * Called automatically by server adapters that support the singleton pool
     * mode; application code rarely needs to call this directly.
     *
     * @param PoolSingletonSpawner $spawner The spawner implementation.
     */
    public function withPoolSingletonSpawner(PoolSingletonSpawner $spawner): self
    {
        $this->assertNotCompiled('withPoolSingletonSpawner()');

        $this->poolSingletonSpawner = $spawner;

        return $this;
    }

    /**
     * Enable PSR-16 route caching to avoid re-parsing attribute routes on each boot.
     *
     * Closure-based routes are always excluded from the cache because closures
     * cannot be serialised. They are re-added from the live route collection on
     * every cache hit.
     *
     * @param CacheInterface $cache PSR-16 simple-cache implementation.
     * @param string|null    $key   Custom cache key; defaults to `'nexus.http.routes'`.
     */
    public function withRouteCache(CacheInterface $cache, ?string $key = null): self
    {
        $this->assertNotCompiled('withRouteCache()');

        $this->routeCache = $cache;

        if ($key !== null) {
            $this->routeCacheKey = $key;
        }

        return $this;
    }

    /**
     * Disable the built-in exception-handler middleware.
     *
     * Use this when you want full control over exception handling, e.g. when
     * integrating with a framework that supplies its own error-handling layer.
     */
    public function withoutDefaultExceptionHandler(): self
    {
        $this->assertNotCompiled('withoutDefaultExceptionHandler()');

        $this->useDefaultExceptionHandler = false;

        return $this;
    }

    /**
     * Snapshot of every route registered so far — both the ones already
     * promoted into the route collection (via attribute discovery or by
     * a prior `compile()`) and the ones still queued in pending builders.
     *
     * Intended for index pages, smoke tests, and admin/debugging tooling.
     * Returns immutable {@see RouteSummary} value objects so callers don't
     * couple to the internal `Route` shape.
     *
     * @return list<RouteSummary>
     */
    public function registeredRoutes(): array
    {
        $summaries = [];

        foreach ($this->routes->all() as $route) {
            $summaries[] = RouteSummary::fromRoute($route);
        }

        foreach ($this->pendingBuilders as $builder) {
            $summaries[] = RouteSummary::fromRoute($builder->build());
        }

        return $summaries;
    }

    private function buildRegistry(): ParamResolverRegistry
    {
        $registry = (new ParamResolverRegistry())
            ->with(new FromActorResolver())
            ->with(new FromBodyResolver())
            ->with(new FromServiceResolver())
            ->with(new ServerRequestResolver())
            ->with(new PerRequestScopeResolver())
            ->with(new PathParamResolver())
            ->with(new ContainerFallbackResolver());

        foreach ($this->paramResolvers as $resolver) {
            $registry = $registry->with($resolver);
        }

        return $registry;
    }

    private function registerRoute(string $method, string $path, string|Closure $handler): RouteBuilder
    {
        $this->assertNotCompiled('route registration');

        $builder = new RouteBuilder($method, $path, $handler);
        $this->pendingBuilders[] = $builder;

        return $builder;
    }

    /**
     * @throws HttpAppAlreadyCompiledException When the DSL is mutated after compile().
     */
    private function assertNotCompiled(string $operation): void
    {
        if ($this->compiled) {
            throw new HttpAppAlreadyCompiledException($operation);
        }
    }
}
