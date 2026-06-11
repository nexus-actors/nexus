<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Discovery;

use Monadial\Nexus\Http\Discovery\RouteDiscoverer;
use Monadial\Nexus\Http\Tests\Unit\Discovery\Fixtures\DiscoveredAction;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RouteDiscoverer::class)]
final class RouteDiscovererTest extends TestCase
{
    #[Test]
    public function discovers_route_attribute_on_action_class(): void
    {
        $discoverer = new RouteDiscoverer();

        $routes = $discoverer->discover(__DIR__ . '/Fixtures');

        self::assertCount(1, $routes);
        self::assertSame('GET', $routes[0]->method);
        self::assertSame('/discovered/{id}', $routes[0]->path);
        self::assertSame(DiscoveredAction::class, $routes[0]->handler);
        self::assertSame(['App\\Mw'], $routes[0]->middleware);
        self::assertSame('discovered.show', $routes[0]->name);
    }

    #[Test]
    public function nonexistent_directory_returns_empty(): void
    {
        $discoverer = new RouteDiscoverer();
        self::assertSame([], $discoverer->discover('/does/not/exist'));
    }
}
