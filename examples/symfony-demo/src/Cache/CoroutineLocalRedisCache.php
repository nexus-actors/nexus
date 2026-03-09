<?php

declare(strict_types=1);

namespace App\Cache;

use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * Coroutine-safe cache adapter that borrows a \Redis connection from
 * SwooleRedisPool for the duration of each Swoole coroutine.
 *
 * On first use within a coroutine, a \Redis connection is borrowed from the
 * pool and a per-coroutine TagAwareAdapter is created. Swoole::defer() returns
 * the connection when the coroutine ends — keeping actors' connections alive
 * for the actor's lifetime and HTTP request connections for the request duration.
 *
 * Falls back to FilesystemAdapter when no pool is available (CLI, tests).
 */
final class CoroutineLocalRedisCache implements TagAwareCacheInterface
{
    /** @var array<int, TagAwareAdapter> */
    private array $localAdapters = [];

    /** @var array<int, \Redis> */
    private array $localRedis = [];

    private readonly TagAwareAdapter $fallback;

    public function __construct(private readonly string $namespace = '', private readonly int $defaultLifetime = 0)
    {
        $this->fallback = new TagAwareAdapter(
            new FilesystemAdapter($namespace, $defaultLifetime),
        );
    }

    public function get(string $key, callable $callback, ?float $beta = null, ?array &$metadata = null): mixed
    {
        return $this->adapter()->get($key, $callback, $beta, $metadata);
    }

    public function delete(string $key): bool
    {
        return $this->adapter()->delete($key);
    }

    public function invalidateTags(array $tags): bool
    {
        return $this->adapter()->invalidateTags($tags);
    }

    private function adapter(): TagAwareAdapter
    {
        $pool = SwooleRedisPool::current();

        if ($pool === null) {
            return $this->fallback;
        }

        $cid = \Swoole\Coroutine::getCid();

        if ($cid === -1) {
            return $this->fallback;
        }

        if (!isset($this->localAdapters[$cid])) {
            $redis = $pool->get();
            $this->localRedis[$cid]    = $redis;
            $this->localAdapters[$cid] = new TagAwareAdapter(
                new RedisAdapter($redis, $this->namespace, $this->defaultLifetime),
            );

            \Swoole\Coroutine::defer(function () use ($cid, $pool): void {
                if (isset($this->localRedis[$cid])) {
                    $pool->put($this->localRedis[$cid]);
                    unset($this->localRedis[$cid], $this->localAdapters[$cid]);
                }
            });
        }

        return $this->localAdapters[$cid];
    }
}
