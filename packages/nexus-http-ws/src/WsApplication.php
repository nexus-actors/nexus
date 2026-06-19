<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Ws;

use Closure;
use InvalidArgumentException;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Http\Actor\PoolSingletonSpawner;
use Monadial\Nexus\Http\App\CompiledHttpApp;
use Monadial\Nexus\Http\App\ErrorMode;
use Monadial\Nexus\Http\Dsl\ActorRegistration;
use Monadial\Nexus\Http\Dsl\RouteBuilder;
use Monadial\Nexus\Http\Dsl\RouteGroup;
use Monadial\Nexus\Http\Handler\Resolver\ParamResolver;
use Monadial\Nexus\Http\Routing\RouteSummary;
use Monadial\Nexus\Http\Ws\WebSocket\ChannelActorRegistry;
use Monadial\Nexus\Http\Ws\WebSocket\Exception\DuplicateRouteException;
use Monadial\Nexus\Http\Ws\WebSocket\HandlerInstantiator;
use Monadial\Nexus\Http\Ws\WebSocket\InMemoryConnectionTable;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketChannelActor;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketDispatcher;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketHandler;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketRoute;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketRouter;
use Monadial\Nexus\Serialization\MessageSerializer;
use Override;
use Psr\Container\ContainerInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;

/**
 * @psalm-api
 *
 * Decorates any Application (typically HttpApplication) and adds the
 * WebSocket DSL. Compiles to CompiledWsApplication.
 */
final class WsApplication implements Application
{
    /** @var list<WebSocketRoute> */
    private array $wsRoutes = [];

    private ?ContainerInterface $container = null;

    private ?LoggerInterface $logger = null;

    private function __construct(private readonly Application $inner, private readonly ActorSystem $system) {}

    public static function decorate(Application $inner, ActorSystem $system): self
    {
        return new self($inner, $system);
    }

    public static function create(ActorSystem $system): self
    {
        return new self(HttpApplication::create($system), $system);
    }

    public function withLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;

        return $this;
    }

    public function withContainer(ContainerInterface $container): self
    {
        $this->container = $container;

        return $this;
    }

    public function inner(): Application
    {
        return $this->inner;
    }

    // ============ Application surface — delegate to $this->inner ============

    #[Override]
    public function get(string $path, string|Closure $handler): RouteBuilder
    {
        return $this->inner->get($path, $handler);
    }

    #[Override]
    public function post(string $path, string|Closure $handler): RouteBuilder
    {
        return $this->inner->post($path, $handler);
    }

    #[Override]
    public function put(string $path, string|Closure $handler): RouteBuilder
    {
        return $this->inner->put($path, $handler);
    }

    #[Override]
    public function patch(string $path, string|Closure $handler): RouteBuilder
    {
        return $this->inner->patch($path, $handler);
    }

    #[Override]
    public function delete(string $path, string|Closure $handler): RouteBuilder
    {
        return $this->inner->delete($path, $handler);
    }

    #[Override]
    public function group(string $prefix, Closure $register): RouteGroup
    {
        return $this->inner->group($prefix, $register);
    }

    #[Override]
    public function middleware(string|MiddlewareInterface $middleware): self
    {
        $this->inner->middleware($middleware);

        return $this;
    }

    #[Override]
    public function paramResolver(ParamResolver $resolver, bool $override = false): self
    {
        $this->inner->paramResolver($resolver, $override);

        return $this;
    }

    #[Override]
    public function actor(string $name, Props $props): ActorRegistration
    {
        return $this->inner->actor($name, $props);
    }

    #[Override]
    public function perRequestActor(string $name, Props $props): ActorRegistration
    {
        return $this->inner->perRequestActor($name, $props);
    }

    #[Override]
    public function discover(string $directory): self
    {
        $this->inner->discover($directory);

        return $this;
    }

    #[Override]
    public function errorMode(ErrorMode $mode): self
    {
        $this->inner->errorMode($mode);

        return $this;
    }

    /**
     * @template TException of \Throwable
     * @param class-string<TException> $exceptionClass
     * @param Closure(TException, \Psr\Http\Message\ServerRequestInterface): \Psr\Http\Message\ResponseInterface $mapper
     */
    #[Override]
    public function onException(string $exceptionClass, Closure $mapper): self
    {
        $this->inner->onException($exceptionClass, $mapper);

        return $this;
    }

    #[Override]
    public function requiresPoolSingleton(): bool
    {
        return $this->inner->requiresPoolSingleton();
    }

    #[Override]
    public function withPoolSingletonSpawner(PoolSingletonSpawner $spawner): self
    {
        $this->inner->withPoolSingletonSpawner($spawner);

        return $this;
    }

    #[Override]
    public function withMessageSerializer(MessageSerializer $serializer): self
    {
        $this->inner->withMessageSerializer($serializer);

        return $this;
    }

    #[Override]
    public function withRouteCache(CacheInterface $cache, ?string $key = null): self
    {
        $this->inner->withRouteCache($cache, $key);

        return $this;
    }

    #[Override]
    public function withoutDefaultExceptionHandler(): self
    {
        $this->inner->withoutDefaultExceptionHandler();

        return $this;
    }

    #[Override]
    public function clearRouteCache(): void
    {
        $this->inner->clearRouteCache();
    }

    /** @return list<RouteSummary> */
    #[Override]
    public function registeredRoutes(): array
    {
        return $this->inner->registeredRoutes();
    }

    #[Override]
    public function compile(): CompiledWsApplication
    {
        $compiledHttp = $this->compileInner();
        $router = WebSocketRouter::build($this->wsRoutes);
        $container = $this->container ?? new EmptyContainer();
        $table = new InMemoryConnectionTable();
        $dispatcher = new WebSocketDispatcher(
            $router,
            $table,
            new ChannelActorRegistry($this->system, $this->logger),
            new HandlerInstantiator($container, $this->logger),
            $this->logger,
        );

        return new CompiledWsApplication($compiledHttp, $router, $dispatcher, $container);
    }

    // ============ WebSocket DSL additions ============

    /** @param class-string<WebSocketHandler> $handlerClass */
    public function ws(string $path, string $handlerClass): self
    {
        $this->guardDuplicate($path);
        $this->wsRoutes[] = WebSocketRoute::handler($path, $handlerClass);

        return $this;
    }

    /** @param class-string<WebSocketChannelActor> $actorClass */
    public function channel(string $path, string $actorClass, string $key): self
    {
        if ($key === '') {
            throw new InvalidArgumentException("WsApplication::channel('{$path}') requires a non-empty key parameter.");
        }

        $this->guardDuplicate($path);
        $this->wsRoutes[] = WebSocketRoute::channel($path, $actorClass, $key);

        return $this;
    }

    private function compileInner(): CompiledHttpApp
    {
        $compiled = $this->inner->compile();

        if ($compiled instanceof CompiledHttpApplication) {
            return $compiled->inner();
        }

        if ($compiled instanceof CompiledWsApplication) {
            throw new RuntimeException(
                'WsApplication cannot decorate another WsApplication. Decorate an HttpApplication instead.',
            );
        }

        throw new RuntimeException('Unsupported inner CompiledApplication type: ' . $compiled::class);
    }

    private function guardDuplicate(string $path): void
    {
        foreach ($this->wsRoutes as $r) {
            if ($r->path === $path) {
                throw new DuplicateRouteException("WebSocket route '{$path}' already registered.");
            }
        }
    }
}
