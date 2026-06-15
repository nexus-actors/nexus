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
use Monadial\Nexus\Serialization\MessageSerializer;
use Psr\Http\Server\MiddlewareInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * @psalm-api
 *
 * Defines the HTTP surface (every method nexus-http's HttpApp exposes today)
 * plus compile(). Concrete impls: HttpApplication (HTTP-only) and
 * WsApplication (decorator that adds WebSocket).
 */
interface Application
{
    public function get(string $path, string|Closure $handler): RouteBuilder;

    public function post(string $path, string|Closure $handler): RouteBuilder;

    public function put(string $path, string|Closure $handler): RouteBuilder;

    public function patch(string $path, string|Closure $handler): RouteBuilder;

    public function delete(string $path, string|Closure $handler): RouteBuilder;

    public function group(string $prefix, Closure $register): RouteGroup;

    public function middleware(string|MiddlewareInterface $middleware): self;

    public function paramResolver(ParamResolver $resolver, bool $override = false): self;

    public function actor(string $name, Props $props): ActorRegistration;

    public function perRequestActor(string $name, Props $props): ActorRegistration;

    public function discover(string $directory): self;

    public function errorMode(ErrorMode $mode): self;

    public function onException(string $exceptionClass, Closure $mapper): self;

    public function requiresPoolSingleton(): bool;

    public function withPoolSingletonSpawner(PoolSingletonSpawner $spawner): self;

    public function withMessageSerializer(MessageSerializer $serializer): self;

    public function withRouteCache(CacheInterface $cache, ?string $key = null): self;

    public function withoutDefaultExceptionHandler(): self;

    public function clearRouteCache(): void;

    public function compile(): CompiledApplication;
}
