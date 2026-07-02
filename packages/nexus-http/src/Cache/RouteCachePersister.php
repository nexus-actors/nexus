<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Cache;

use Monadial\Nexus\Http\Routing\Route;
use Psr\SimpleCache\CacheInterface;

/**
 * @psalm-api
 *
 * PSR-16-backed persistence of route metadata. Closure handlers are skipped
 * from the cache (they can't be serialized) — callers re-add them from the
 * in-memory collection after a hit.
 *
 * Cache payload shape: list of [method, path, handler, middleware[], name]
 * arrays. var_export-safe so any PSR-16 backend can serialize it.
 */
final class RouteCachePersister
{
    public function __construct(private readonly CacheInterface $cache, private readonly string $key) {}

    public function clear(): void
    {
        $this->cache->delete($this->key);
    }

    /**
     * Returns the cached routes, or null on miss.
     *
     * @return list<Route>|null
     */
    public function load(): ?array
    {
        /** @var list<array{0: string, 1: string, 2: string, 3: list<string>, 4: ?string}>|null $payload */
        $payload = $this->cache->get($this->key);

        if ($payload === null) {
            return null;
        }

        $routes = [];

        foreach ($payload as $row) {
            $routes[] = new Route($row[0], $row[1], $row[2], $row[3], $row[4]);
        }

        return $routes;
    }

    /**
     * Persist the string-handler subset of the given routes.
     *
     * @param list<Route> $routes
     */
    public function save(array $routes): void
    {
        $payload = [];

        foreach ($routes as $route) {
            if (!is_string($route->handler)) {
                continue;
            }

            $payload[] = [
                $route->method,
                $route->path,
                $route->handler,
                $route->middleware,
                $route->name,
            ];
        }

        $this->cache->set($this->key, $payload);
    }
}
