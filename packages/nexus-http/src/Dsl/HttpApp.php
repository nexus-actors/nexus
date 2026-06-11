<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Dsl;

use Closure;
use LogicException;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Http\Actor\ActorMode;
use Monadial\Nexus\Http\Actor\ActorRegistry;
use Monadial\Nexus\Http\Actor\ResolvedActorTable;
use Monadial\Nexus\Http\App\CompiledHttpApp;
use Monadial\Nexus\Http\App\ErrorMode;
use Monadial\Nexus\Http\Discovery\RouteDiscoverer;
use Monadial\Nexus\Http\Exception\DefaultMappers;
use Monadial\Nexus\Http\Exception\ExceptionMapperRegistry;
use Monadial\Nexus\Http\Handler\HandlerResolver;
use Monadial\Nexus\Http\Middleware\ExceptionHandlerMiddleware;
use Monadial\Nexus\Http\Middleware\MiddlewareInvoker;
use Monadial\Nexus\Http\Middleware\MiddlewarePipeline;
use Monadial\Nexus\Http\Middleware\RouterMiddleware;
use Monadial\Nexus\Http\Routing\Dispatcher;
use Monadial\Nexus\Http\Routing\RouteCollection;
use Monadial\Nexus\WorkerPool\WorkerNode;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @psalm-api
 *
 * Fluent DSL for building an HTTP app. Mutable during construction.
 * Terminal operation `compile()` returns an immutable {@see CompiledHttpApp}
 * — server adapters consume that, not the DSL.
 *
 * HttpApp itself does NOT implement RequestHandlerInterface. The DSL builds,
 * the CompiledHttpApp serves.
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

    private ErrorMode $errorMode = ErrorMode::Production;

    private bool $useDefaultExceptionHandler = true;

    private ?WorkerNode $workerNode = null;

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

    public static function create(
        ActorSystem $system,
        ?ContainerInterface $container = null,
        ?EventDispatcherInterface $events = null,
        ?LoggerInterface $logger = null,
    ): self {
        return new self($system, $container, $events, $logger);
    }

    // ─── Actors ────────────────────────────────────────────────────

    public function actor(string $name, Props $props): ActorRegistration
    {
        return $this->registry->register($name, $props, ActorMode::WorkerLocal);
    }

    // ─── Compile ───────────────────────────────────────────────────

    /**
     * Freeze the DSL state into an immutable, ready-to-serve CompiledHttpApp.
     * Calling compile() multiple times yields independent CompiledHttpApp
     * instances reflecting the DSL state at each call.
     */
    public function compile(): CompiledHttpApp
    {
        // 1. Discovered routes — append before pending builders so they share the same collection.
        $discoverer = new RouteDiscoverer();

        foreach ($this->discoveryDirs as $dir) {
            foreach ($discoverer->discover($dir) as $route) {
                $this->routes->add($route);
            }
        }

        // 2. Promote pending route builders BEFORE the dispatcher is built.
        foreach ($this->pendingBuilders as $builder) {
            $this->routes->add($builder->build());
        }

        $this->pendingBuilders = [];

        // 2. Resolve actor table.
        $entries = $this->registry->freeze();
        $table = ResolvedActorTable::build($entries, $this->system, $this->workerNode);

        // 3. Resolve handlers per route.
        $resolver = new HandlerResolver($table, $this->container);
        $routes = $this->routes->all();
        $handlersByKey = [];
        $routeMwsByKey = [];

        foreach ($routes as $route) {
            $key = $route->method . ':' . $route->path;
            /** @psalm-suppress ArgumentTypeCoercion */
            $handlersByKey[$key] = $resolver->resolve($route->handler);
            $routeMwsByKey[$key] = $route->middleware;
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
            Dispatcher::build($routes),
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
            /** @psalm-suppress ArgumentTypeCoercion */
            $stack[] = $mw instanceof MiddlewareInterface
                ? $mw
                : $this->resolveMiddleware($mw);
        }

        $stack[] = $router;

        /** @psalm-suppress UnusedClosureParam */
        $tail = static function (ServerRequestInterface $r): ResponseInterface {
            throw new LogicException('RouterMiddleware did not produce a response');
        };

        return new CompiledHttpApp(new MiddlewareInvoker($stack, $tail), $this->events);
    }

    public function delete(string $path, string|Closure $handler): RouteBuilder
    {
        return $this->registerRoute('DELETE', $path, $handler);
    }

    public function discover(string $directory): self
    {
        $this->discoveryDirs[] = $directory;

        return $this;
    }

    public function errorMode(ErrorMode $mode): self
    {
        $this->errorMode = $mode;

        return $this;
    }

    // ─── Routing ───────────────────────────────────────────────────

    public function get(string $path, string|Closure $handler): RouteBuilder
    {
        return $this->registerRoute('GET', $path, $handler);
    }

    /** @param Closure(RouteGroup): void $register */
    public function group(string $prefix, Closure $register): RouteGroup
    {
        $group = new RouteGroup($prefix);
        $register($group);

        foreach ($group->commit() as $route) {
            $this->routes->add($route);
        }

        return $group;
    }

    // ─── Middleware ────────────────────────────────────────────────

    public function middleware(string|MiddlewareInterface $middleware): self
    {
        $this->globalMiddleware[] = $middleware;

        return $this;
    }

    // ─── Errors ────────────────────────────────────────────────────

    /** @param Closure(Throwable, ServerRequestInterface): ResponseInterface $mapper */
    public function onException(string $exceptionClass, Closure $mapper): self
    {
        $this->userExceptionRegistrations[] = static function (ExceptionMapperRegistry $r) use ($exceptionClass, $mapper): void {
            $r->register($exceptionClass, $mapper);
        };

        return $this;
    }

    public function patch(string $path, string|Closure $handler): RouteBuilder
    {
        return $this->registerRoute('PATCH', $path, $handler);
    }

    public function perRequestActor(string $name, Props $props): ActorRegistration
    {
        return $this->registry->register($name, $props, ActorMode::PerRequest);
    }

    public function post(string $path, string|Closure $handler): RouteBuilder
    {
        return $this->registerRoute('POST', $path, $handler);
    }

    public function put(string $path, string|Closure $handler): RouteBuilder
    {
        return $this->registerRoute('PUT', $path, $handler);
    }

    // ─── Capability flags ──────────────────────────────────────────

    public function requiresPoolSingleton(): bool
    {
        foreach ($this->registry->freeze() as $entry) {
            if ($entry->mode === ActorMode::PoolSingleton) {
                return true;
            }
        }

        return false;
    }

    public function withWorkerNode(WorkerNode $node): self
    {
        $this->workerNode = $node;

        return $this;
    }

    public function withoutDefaultExceptionHandler(): self
    {
        $this->useDefaultExceptionHandler = false;

        return $this;
    }

    private function registerRoute(string $method, string $path, string|Closure $handler): RouteBuilder
    {
        $builder = new RouteBuilder($method, $path, $handler);
        $this->pendingBuilders[] = $builder;

        return $builder;
    }

    /** @param class-string $class */
    private function resolveMiddleware(string $class): MiddlewareInterface
    {
        if ($this->container !== null && $this->container->has($class)) {
            /** @var MiddlewareInterface */
            return $this->container->get($class);
        }

        /**
         * @var MiddlewareInterface
         * @psalm-suppress MixedMethodCall
         */
        return new $class();
    }
}
