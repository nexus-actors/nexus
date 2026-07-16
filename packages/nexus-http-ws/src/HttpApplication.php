<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Ws;

use Closure;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Http\Actor\PoolSingletonSpawner;
use Monadial\Nexus\Http\App\ErrorMode;
use Monadial\Nexus\Http\Dsl\ActorRegistration;
use Monadial\Nexus\Http\Dsl\HttpApp;
use Monadial\Nexus\Http\Dsl\RouteBuilder;
use Monadial\Nexus\Http\Dsl\RouteGroup;
use Monadial\Nexus\Http\Handler\Resolver\ParamResolver;
use Monadial\Nexus\Http\Routing\RouteSummary;
use Monadial\Nexus\Serialization\MessageSerializer;
use Override;
use Psr\Http\Server\MiddlewareInterface;
use Psr\SimpleCache\CacheInterface;

/** @psalm-api */
final readonly class HttpApplication implements Application
{
    private function __construct(private HttpApp $http) {}

    public static function create(ActorSystem $system): self
    {
        return new self(HttpApp::create($system));
    }

    #[Override]
    public function get(string $path, string|Closure $handler): RouteBuilder
    {
        return $this->http->get($path, $handler);
    }

    #[Override]
    public function post(string $path, string|Closure $handler): RouteBuilder
    {
        return $this->http->post($path, $handler);
    }

    #[Override]
    public function put(string $path, string|Closure $handler): RouteBuilder
    {
        return $this->http->put($path, $handler);
    }

    #[Override]
    public function patch(string $path, string|Closure $handler): RouteBuilder
    {
        return $this->http->patch($path, $handler);
    }

    #[Override]
    public function delete(string $path, string|Closure $handler): RouteBuilder
    {
        return $this->http->delete($path, $handler);
    }

    #[Override]
    public function group(string $prefix, Closure $register): RouteGroup
    {
        return $this->http->group($prefix, $register);
    }

    #[Override]
    public function middleware(string|MiddlewareInterface $middleware): self
    {
        $this->http->middleware($middleware);

        return $this;
    }

    #[Override]
    public function paramResolver(ParamResolver $resolver, bool $override = false): self
    {
        $this->http->paramResolver($resolver, $override);

        return $this;
    }

    #[Override]
    public function actor(string $name, Props $props): ActorRegistration
    {
        return $this->http->actor($name, $props);
    }

    #[Override]
    public function perRequestActor(string $name, Props $props): ActorRegistration
    {
        return $this->http->perRequestActor($name, $props);
    }

    #[Override]
    public function discover(string $directory): self
    {
        $this->http->discover($directory);

        return $this;
    }

    #[Override]
    public function errorMode(ErrorMode $mode): self
    {
        $this->http->errorMode($mode);

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
        $this->http->onException($exceptionClass, $mapper);

        return $this;
    }

    #[Override]
    public function requiresPoolSingleton(): bool
    {
        return $this->http->requiresPoolSingleton();
    }

    #[Override]
    public function withPoolSingletonSpawner(PoolSingletonSpawner $spawner): self
    {
        $this->http->withPoolSingletonSpawner($spawner);

        return $this;
    }

    #[Override]
    public function withMessageSerializer(MessageSerializer $serializer): self
    {
        $this->http->withMessageSerializer($serializer);

        return $this;
    }

    #[Override]
    public function withRouteCache(CacheInterface $cache, ?string $key = null): self
    {
        $this->http->withRouteCache($cache, $key);

        return $this;
    }

    #[Override]
    public function withoutDefaultExceptionHandler(): self
    {
        $this->http->withoutDefaultExceptionHandler();

        return $this;
    }

    #[Override]
    public function clearRouteCache(): void
    {
        $this->http->clearRouteCache();
    }

    #[Override]
    public function compile(): CompiledHttpApplication
    {
        return new CompiledHttpApplication($this->http->compile());
    }

    /** @return list<RouteSummary> */
    #[Override]
    public function registeredRoutes(): array
    {
        return $this->http->registeredRoutes();
    }

    public function inner(): HttpApp
    {
        return $this->http;
    }
}
