<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Routing;

use Monadial\Nexus\Http\Routing\Dispatcher;
use Monadial\Nexus\Http\Routing\DispatchResult;
use Monadial\Nexus\Http\Routing\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Dispatcher::class)]
#[CoversClass(DispatchResult::class)]
final class DispatcherTest extends TestCase
{
    #[Test]
    public function dispatch_returns_matched_route_with_path_params(): void
    {
        $route = new Route('GET', '/users/{id}', 'App\\GetUser', [], null);
        $dispatcher = Dispatcher::build([$route]);

        $result = $dispatcher->dispatch('GET', '/users/42');

        self::assertSame(DispatchResult::FOUND, $result->status);
        self::assertSame($route, $result->route);
        self::assertSame(['id' => '42'], $result->pathParams);
    }

    #[Test]
    public function dispatch_returns_method_not_allowed_with_allow_header(): void
    {
        $dispatcher = Dispatcher::build([new Route('GET', '/users', 'A', [], null)]);

        $result = $dispatcher->dispatch('POST', '/users');

        self::assertSame(DispatchResult::METHOD_NOT_ALLOWED, $result->status);
        self::assertSame(['GET'], $result->allowedMethods);
    }

    #[Test]
    public function dispatch_returns_not_found(): void
    {
        $dispatcher = Dispatcher::build([new Route('GET', '/users', 'A', [], null)]);

        $result = $dispatcher->dispatch('GET', '/orders');

        self::assertSame(DispatchResult::NOT_FOUND, $result->status);
        self::assertNull($result->route);
    }
}
