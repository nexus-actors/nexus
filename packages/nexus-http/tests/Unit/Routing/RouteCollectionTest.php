<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Routing;

use Monadial\Nexus\Http\Exception\DuplicateRouteNameException;
use Monadial\Nexus\Http\Routing\Route;
use Monadial\Nexus\Http\Routing\RouteCollection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RouteCollection::class)]
final class RouteCollectionTest extends TestCase
{
    #[Test]
    public function add_appends_routes_in_order(): void
    {
        $collection = new RouteCollection();
        $a = new Route('GET', '/a', 'A', [], null);
        $b = new Route('GET', '/b', 'B', [], null);

        $collection->add($a);
        $collection->add($b);

        self::assertSame([$a, $b], $collection->all());
    }

    #[Test]
    public function add_throws_on_duplicate_name(): void
    {
        $collection = new RouteCollection();
        $collection->add(new Route('GET', '/a', 'A', [], 'shared'));

        $this->expectException(DuplicateRouteNameException::class);
        $collection->add(new Route('POST', '/b', 'B', [], 'shared'));
    }

    #[Test]
    public function find_by_name_returns_matching_route(): void
    {
        $collection = new RouteCollection();
        $route = new Route('GET', '/users/{id}', 'App\\GetUser', [], 'users.show');
        $collection->add($route);

        self::assertSame($route, $collection->findByName('users.show'));
    }

    #[Test]
    public function find_by_name_returns_null_when_missing(): void
    {
        $collection = new RouteCollection();

        self::assertNull($collection->findByName('nope'));
    }
}
