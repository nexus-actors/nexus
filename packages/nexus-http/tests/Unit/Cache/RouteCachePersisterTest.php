<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Cache;

use Monadial\Nexus\Http\Cache\RouteCachePersister;
use Monadial\Nexus\Http\Routing\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

#[CoversClass(RouteCachePersister::class)]
final class RouteCachePersisterTest extends TestCase
{
    #[Test]
    public function clear_evicts_the_cached_routes(): void
    {
        $cache = $this->newCache();
        $persister = new RouteCachePersister($cache, 'routes');
        $persister->save([new Route('GET', '/a', 'App\\A', [], null)]);

        $persister->clear();

        self::assertNull($persister->load());
    }

    #[Test]
    public function load_returns_null_on_cold_cache(): void
    {
        $persister = new RouteCachePersister($this->newCache(), 'routes');

        self::assertNull($persister->load());
    }

    #[Test]
    public function save_skips_closure_handlers(): void
    {
        $cache = $this->newCache();
        $persister = new RouteCachePersister($cache, 'routes');
        $routes = [
            new Route('GET', '/closure', static fn(): null => null, [], null),
            new Route('GET', '/string', 'App\\X', [], null),
        ];

        $persister->save($routes);
        $loaded = $persister->load();

        self::assertNotNull($loaded);
        self::assertCount(1, $loaded);
        self::assertSame('/string', $loaded[0]->path);
    }

    #[Test]
    public function save_then_load_round_trips_fields(): void
    {
        $cache = $this->newCache();
        $persister = new RouteCachePersister($cache, 'routes');
        $routes = [
            new Route('GET', '/a/{id}', 'App\\A', ['M1'], 'a.show'),
            new Route('POST', '/b', 'App\\B', [], null),
        ];

        $persister->save($routes);
        $loaded = $persister->load();

        self::assertNotNull($loaded);
        self::assertCount(2, $loaded);
        self::assertSame('/a/{id}', $loaded[0]->path);
        self::assertSame('a.show', $loaded[0]->name);
        self::assertSame(['M1'], $loaded[0]->middleware);
        self::assertSame('App\\B', $loaded[1]->handler);
    }

    private function newCache(): CacheInterface
    {
        return new Psr16Cache(new ArrayAdapter());
    }
}
