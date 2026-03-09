<?php

declare(strict_types=1);

namespace App\Cache;

use RuntimeException;
use Swoole\Database\RedisConfig;
use Swoole\Database\RedisPool;

/**
 * Coroutine-safe Redis connection pool backed by Swoole\Database\RedisPool.
 *
 * Created once per worker process in ConnectionPoolBootstrapper::onWorkerStart().
 * Stored as a static singleton so services can access it without Symfony DI
 * (which would conflict with the synthetic/lazy initialization pattern).
 *
 * Pool size should be >= max_concurrent_coroutines_using_redis_simultaneously.
 * In practice: number_of_actors + expected_concurrent_http_requests.
 */
final class SwooleRedisPool
{
    private static ?self $current = null;

    private RedisPool $inner;

    public function __construct(string $host, int $port, string $auth, int $dbIndex, int $size)
    {
        $config = (new RedisConfig())
            ->withHost($host)
            ->withPort($port)
            ->withTimeout(1.0)
            ->withDbIndex($dbIndex);

        if ($auth !== '') {
            $config = $config->withAuth($auth);
        }

        $this->inner = new RedisPool($config, $size);
    }

    public static function current(): ?self
    {
        return self::$current;
    }

    public static function setCurrent(self $pool): void
    {
        self::$current = $pool;
    }

    public function get(): \Redis
    {
        return $this->inner->get();
    }

    public function put(\Redis $connection): void
    {
        $this->inner->put($connection);
    }

    public function close(): void
    {
        $this->inner->close();
        self::$current = null;
    }
}
