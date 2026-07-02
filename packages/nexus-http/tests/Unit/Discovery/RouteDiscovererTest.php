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
    public function discovers_final_readonly_action_class(): void
    {
        $discoverer = new RouteDiscoverer();

        $routes = $discoverer->discover(__DIR__ . '/Fixtures');

        $readonly = array_values(array_filter(
            $routes,
            static fn($r): bool => $r->path === '/readonly',
        ));

        self::assertCount(1, $readonly);
        self::assertSame('readonly.action', $readonly[0]->name);
    }

    #[Test]
    public function discovers_multiple_route_attributes_on_one_class(): void
    {
        $discoverer = new RouteDiscoverer();

        $routes = $discoverer->discover(__DIR__ . '/Fixtures');

        $multi = array_values(array_filter(
            $routes,
            static fn($r): bool => str_starts_with($r->path, '/multi/'),
        ));

        self::assertCount(2, $multi);
        $paths = array_map(static fn($r): string => $r->path, $multi);
        self::assertContains('/multi/list', $paths);
        self::assertContains('/multi/show/{id}', $paths);
    }

    #[Test]
    public function discovers_route_attribute_on_action_class(): void
    {
        $discoverer = new RouteDiscoverer();

        $routes = $discoverer->discover(__DIR__ . '/Fixtures');

        $discovered = array_values(array_filter(
            $routes,
            static fn($r): bool => $r->path === '/discovered/{id}',
        ));

        self::assertCount(1, $discovered);
        self::assertSame('GET', $discovered[0]->method);
        self::assertSame(DiscoveredAction::class, $discovered[0]->handler);
        self::assertSame(['App\\Mw'], $discovered[0]->middleware);
        self::assertSame('discovered.show', $discovered[0]->name);
    }

    #[Test]
    public function nonexistent_directory_returns_empty(): void
    {
        $discoverer = new RouteDiscoverer();
        self::assertSame([], $discoverer->discover('/does/not/exist'));
    }
}
