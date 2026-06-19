<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Ws;

use Closure;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Http\Actor\PoolSingletonSpawner;
use Monadial\Nexus\Http\App\ErrorMode;
use Monadial\Nexus\Http\Dsl\ActorRegistration;
use Monadial\Nexus\Http\Dsl\RouteBuilder;
use Monadial\Nexus\Http\Dsl\RouteGroup;
use Monadial\Nexus\Http\Handler\Resolver\ParamResolver;
use Monadial\Nexus\Http\Routing\RouteSummary;
use Monadial\Nexus\Serialization\MessageSerializer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\SimpleCache\CacheInterface;
use Throwable;

/**
 * @psalm-api
 *
 * Defines the HTTP surface (every method nexus-http's HttpApp exposes today)
 * plus compile(). Concrete impls: HttpApplication (HTTP-only) and
 * WsApplication (decorator that adds WebSocket).
 */
interface Application
{
    /**
     * @param class-string|Closure $handler  Either a class FQN whose `__invoke`
     *        is resolved by the registry, or a Closure whose params are
     *        resolved by registered ParamResolvers (`#[FromPrincipal]`,
     *        `#[FromBody]`, `ServerRequestInterface`, ...) and which returns
     *        either a `ResponseInterface` or a `Future<ResponseInterface>`.
     */
    public function get(string $path, string|Closure $handler): RouteBuilder;

    /** @param class-string|Closure $handler  See {@see self::get()} for the closure contract. */
    public function post(string $path, string|Closure $handler): RouteBuilder;

    /** @param class-string|Closure $handler  See {@see self::get()} for the closure contract. */
    public function put(string $path, string|Closure $handler): RouteBuilder;

    /** @param class-string|Closure $handler  See {@see self::get()} for the closure contract. */
    public function patch(string $path, string|Closure $handler): RouteBuilder;

    /** @param class-string|Closure $handler  See {@see self::get()} for the closure contract. */
    public function delete(string $path, string|Closure $handler): RouteBuilder;

    /** @param Closure(RouteGroup): void $register */
    public function group(string $prefix, Closure $register): RouteGroup;

    public function middleware(string|MiddlewareInterface $middleware): self;

    public function paramResolver(ParamResolver $resolver, bool $override = false): self;

    public function actor(string $name, Props $props): ActorRegistration;

    public function perRequestActor(string $name, Props $props): ActorRegistration;

    public function discover(string $directory): self;

    public function errorMode(ErrorMode $mode): self;

    /**
     * @template TException of Throwable
     * @param class-string<TException> $exceptionClass
     * @param Closure(TException, ServerRequestInterface): ResponseInterface $mapper
     */
    public function onException(string $exceptionClass, Closure $mapper): self;

    public function requiresPoolSingleton(): bool;

    public function withPoolSingletonSpawner(PoolSingletonSpawner $spawner): self;

    public function withMessageSerializer(MessageSerializer $serializer): self;

    public function withRouteCache(CacheInterface $cache, ?string $key = null): self;

    public function withoutDefaultExceptionHandler(): self;

    public function clearRouteCache(): void;

    public function compile(): CompiledApplication;

    /**
     * Snapshot of every route registered so far. Intended for index pages,
     * smoke tests, and admin/debugging tooling.
     *
     * @return list<RouteSummary>
     */
    public function registeredRoutes(): array;
}
