<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Routing;

use Monadial\Nexus\Http\Routing\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Route::class)]
final class RouteTest extends TestCase
{
    #[Test]
    public function exposes_constructor_arguments_as_public_readonly(): void
    {
        $route = new Route('GET', '/users/{id}', 'App\\GetUser', ['Auth'], 'users.show');

        self::assertSame('GET', $route->method);
        self::assertSame('/users/{id}', $route->path);
        self::assertSame('App\\GetUser', $route->handler);
        self::assertSame(['Auth'], $route->middleware);
        self::assertSame('users.show', $route->name);
    }

    #[Test]
    public function with_added_middleware_returns_new_instance_preserving_order(): void
    {
        $original = new Route('POST', '/orders', 'App\\CreateOrder', ['Auth'], null);

        $extended = $original->withAddedMiddleware(['Idempotency']);

        self::assertSame(['Auth'], $original->middleware);
        self::assertSame(['Auth', 'Idempotency'], $extended->middleware);
    }

    #[Test]
    public function with_prefixed_path_joins_prefix_and_path(): void
    {
        $route = new Route('GET', '/users/{id}', 'A', [], null);

        $prefixed = $route->withPrefixedPath('/api/v1');

        self::assertSame('/api/v1/users/{id}', $prefixed->path);
    }

    #[Test]
    public function with_prefixed_path_collapses_trailing_slash_on_prefix(): void
    {
        $route = new Route('GET', '/users', 'A', [], null);

        $prefixed = $route->withPrefixedPath('/api/');

        self::assertSame('/api/users', $prefixed->path);
    }

    #[Test]
    public function with_prefixed_path_handles_empty_prefix(): void
    {
        $route = new Route('GET', '/users', 'A', [], null);

        $prefixed = $route->withPrefixedPath('');

        self::assertSame('/users', $prefixed->path);
    }

    #[Test]
    public function with_prefixed_path_does_not_emit_trailing_slash_for_empty_path(): void
    {
        $route = new Route('GET', '', 'A', [], null);

        $prefixed = $route->withPrefixedPath('/api');

        self::assertSame('/api', $prefixed->path);
    }

    #[Test]
    public function with_prefixed_path_returns_root_for_both_empty(): void
    {
        $route = new Route('GET', '', 'A', [], null);

        $prefixed = $route->withPrefixedPath('');

        self::assertSame('/', $prefixed->path);
    }
}
