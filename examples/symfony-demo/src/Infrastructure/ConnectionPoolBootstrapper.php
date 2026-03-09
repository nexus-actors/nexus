<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Cache\SwooleRedisPool;
use App\Doctrine\SwoolePDOPool;
use Monadial\Nexus\Symfony\Runtime\WorkerStartBootstrapper;
use Override;
use Psr\Container\ContainerInterface;

/**
 * Initializes Swoole connection pools at worker start, before any request is served.
 *
 * Both SwooleRedisPool and SwoolePDOPool are stored as static singletons on their
 * respective classes. They are accessed directly by CoroutineLocalRedisCache and
 * CoroutineLocalConnection without going through Symfony DI — this avoids the
 * synthetic service complexity that arises when the pool must exist before the
 * container is fully built.
 *
 * Pool sizes default to 2× worker thread count. Tune via env vars:
 *   REDIS_POOL_SIZE  (default: 16)
 *   DB_POOL_SIZE     (default: 8)
 */
final class ConnectionPoolBootstrapper implements WorkerStartBootstrapper
{
    #[Override]
    public function onWorkerStart(ContainerInterface $container, int $workerId): void
    {
        $this->initRedisPool();
        $this->initPDOPool();
    }

    private function initRedisPool(): void
    {
        $dsn  = (string) ($_ENV['REDIS_DSN'] ?? 'redis://redis:6379');
        $size = (int) ($_ENV['REDIS_POOL_SIZE'] ?? 16);

        $parsed = $this->parseRedisDsn($dsn);

        SwooleRedisPool::setCurrent(new SwooleRedisPool(
            host: $parsed['host'],
            port: $parsed['port'],
            auth: $parsed['auth'],
            dbIndex: $parsed['db'],
            size: $size,
        ));
    }

    private function initPDOPool(): void
    {
        $url  = (string) ($_ENV['DATABASE_URL'] ?? '');
        $size = (int) ($_ENV['DB_POOL_SIZE'] ?? 8);

        if ($url === '') {
            return;
        }

        $parsed = $this->parseDatabaseUrl($url);

        if ($parsed === null) {
            return;
        }

        SwoolePDOPool::setCurrent(new SwoolePDOPool(
            driver: $parsed['driver'],
            host: $parsed['host'],
            port: $parsed['port'],
            dbname: $parsed['dbname'],
            username: $parsed['username'],
            password: $parsed['password'],
            charset: $parsed['charset'],
            size: $size,
        ));
    }

    /**
     * Parse redis://[:password@]host[:port][/db] into components.
     *
     * @return array{host: string, port: int, auth: string, db: int}
     */
    private function parseRedisDsn(string $dsn): array
    {
        $url = parse_url($dsn);

        return [
            'host' => (string) ($url['host'] ?? 'redis'),
            'port' => (int) ($url['port'] ?? 6379),
            'auth' => isset($url['pass']) ? (string) $url['pass'] : '',
            'db'   => isset($url['path']) ? (int) ltrim((string) $url['path'], '/') : 0,
        ];
    }

    /**
     * Parse mysql://user:pass@host:port/dbname?serverVersion=8.0 into components.
     *
     * @return array{driver: string, host: string, port: int, dbname: string, username: string, password: string, charset: string}|null
     */
    private function parseDatabaseUrl(string $url): ?array
    {
        $parsed = parse_url($url);

        if ($parsed === false || !isset($parsed['host'])) {
            return null;
        }

        $scheme = (string) ($parsed['scheme'] ?? 'mysql');
        $driver = match (true) {
            str_starts_with($scheme, 'mysql') => 'mysql',
            str_starts_with($scheme, 'pgsql'),
            str_starts_with($scheme, 'postgres') => 'pgsql',
            default => $scheme,
        };

        return [
            'charset'  => 'utf8mb4',
            'dbname'   => ltrim((string) ($parsed['path'] ?? '/nexus_demo'), '/'),
            'driver'   => $driver,
            'host'     => (string) $parsed['host'],
            'password' => isset($parsed['pass']) ? urldecode((string) $parsed['pass']) : '',
            'port'     => (int) ($parsed['port'] ?? 3306),
            'username' => isset($parsed['user']) ? urldecode((string) $parsed['user']) : 'root',
        ];
    }
}
