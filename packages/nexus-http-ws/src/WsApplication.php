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
use Monadial\Nexus\Http\Exception\UnprotectedRouteException;
use Monadial\Nexus\Http\Handler\Resolver\ParamResolver;
use Monadial\Nexus\Http\Middleware\MiddlewareResolver;
use Monadial\Nexus\Http\Routing\RouteSummary;
use Monadial\Nexus\Http\Security\RouteProtection;
use Monadial\Nexus\Http\Ws\WebSocket\ChannelActorRegistry;
use Monadial\Nexus\Http\Ws\WebSocket\Exception\DuplicateRouteException;
use Monadial\Nexus\Http\Ws\WebSocket\HandlerInstantiator;
use Monadial\Nexus\Http\Ws\WebSocket\HandshakeGate;
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

    /** @var list<MiddlewareInterface|class-string<MiddlewareInterface>> */
    private array $wsMiddleware = [];

    /** @var list<ParamResolver> */
    private array $wsParamResolvers = [];

    private int $maxChannels = ChannelActorRegistry::DEFAULT_MAX_CHANNELS;

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

        // Shared with the WebSocket HandlerInstantiator so attribute-driven
        // resolution (e.g. #[FromPrincipal]) works identically on WS handlers.
        $this->wsParamResolvers[] = $resolver;

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
        // Fail closed on authorization (SEC-003): a WS handler class declaring
        // an AuthorizationRequirement attribute must have an enforcer in the
        // pipeline the HandshakeGate runs (global wsMiddleware or per-route).

        foreach ($this->wsRoutes as $wsRoute) {
            $requirement = RouteProtection::requirementOf($wsRoute->targetClass);

            if (
                $requirement !== null
                && !RouteProtection::hasEnforcer([...$this->wsMiddleware, ...$wsRoute->middleware])
            ) {
                throw new UnprotectedRouteException('WS', $wsRoute->path, $wsRoute->targetClass, $requirement);
            }
        }

        $compiledHttp = $this->compileInner();
        $router = WebSocketRouter::build($this->wsRoutes);
        $container = $this->container ?? new EmptyContainer();
        $table = new InMemoryConnectionTable();
        $dispatcher = new WebSocketDispatcher(
            $router,
            $table,
            new ChannelActorRegistry($this->system, $this->logger, $this->maxChannels),
            new HandlerInstantiator($container, $this->logger, userResolvers: $this->wsParamResolvers),
            $this->logger,
        );
        $gate = new HandshakeGate(
            $router,
            $this->wsMiddleware,
            new MiddlewareResolver($this->container),
            $this->logger,
        );

        return new CompiledWsApplication($compiledHttp, $router, $dispatcher, $container, $gate);
    }

    // ============ WebSocket DSL additions ============

    /**
     * Append a PSR-15 middleware applied to EVERY WebSocket upgrade request,
     * before per-route middleware, by the pre-upgrade HandshakeGate. Use this
     * for AuthenticationMiddleware/AuthorizationMiddleware so WebSocket routes
     * share the HTTP auth pipeline and reject unauthorized connections before
     * the 101 switch.
     *
     * @param MiddlewareInterface|class-string<MiddlewareInterface> $middleware
     */
    public function wsMiddleware(MiddlewareInterface|string $middleware): self
    {
        $this->wsMiddleware[] = $middleware;

        return $this;
    }

    /**
     * Cap the number of simultaneously live channel actors (SEC-002). Once the
     * cap is reached, a new channel connection is refused with a 1013 close
     * instead of spawning an unbounded number of actors/refs/mailboxes from an
     * attacker churning unique channel keys. Dead channels (stopped on last
     * close) free their slot. Default {@see ChannelActorRegistry::DEFAULT_MAX_CHANNELS}.
     */
    public function withMaxChannels(int $maxChannels): self
    {
        $this->maxChannels = $maxChannels;

        return $this;
    }

    /**
     * @param class-string<WebSocketHandler> $handlerClass
     * @param list<MiddlewareInterface|class-string<MiddlewareInterface>> $middleware
     *        Per-route middleware run by the HandshakeGate before the upgrade.
     */
    public function ws(string $path, string $handlerClass, array $middleware = []): self
    {
        $this->guardDuplicate($path);
        $this->wsRoutes[] = WebSocketRoute::handler($path, $handlerClass, $middleware);

        return $this;
    }

    /**
     * @param class-string<WebSocketChannelActor> $actorClass
     * @param ?Closure(): WebSocketChannelActor $factory Optional constructor.
     *        When null the dispatcher zero-arg instantiates the class. Pass
     *        a factory when the channel actor needs dependencies (e.g. an
     *        EntityRefFactory or a repository) that DI would otherwise
     *        supply on an HTTP handler.
     * @param list<MiddlewareInterface|class-string<MiddlewareInterface>> $middleware
     *        Per-route middleware run by the HandshakeGate before the upgrade.
     */
    public function channel(
        string $path,
        string $actorClass,
        string $key,
        ?Closure $factory = null,
        array $middleware = [],
    ): self {
        if ($key === '') {
            throw new InvalidArgumentException("WsApplication::channel('{$path}') requires a non-empty key parameter.");
        }

        $this->guardDuplicate($path);
        $this->wsRoutes[] = WebSocketRoute::channel($path, $actorClass, $key, $factory, $middleware);

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
