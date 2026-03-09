<?php

declare(strict_types=1);

namespace App\Doctrine;

use Swoole\Database\PDOConfig;
use Swoole\Database\PDOPool;

/**
 * Coroutine-safe PDO connection pool backed by Swoole\Database\PDOPool.
 *
 * Follows the same static singleton pattern as SwooleRedisPool — created once
 * per worker in ConnectionPoolBootstrapper and accessed by SwoolePoolMiddleware
 * without going through Symfony DI.
 */
final class SwoolePDOPool
{
    private static ?self $current = null;

    private PDOPool $inner;

    public function __construct(
        string $driver,
        string $host,
        int $port,
        string $dbname,
        string $username,
        string $password,
        string $charset,
        int $size,
    ) {
        $config = (new PDOConfig())
            ->withDriver($driver)
            ->withHost($host)
            ->withPort($port)
            ->withDbname($dbname)
            ->withUsername($username)
            ->withPassword($password)
            ->withCharset($charset);

        $this->inner = new PDOPool($config, $size);
    }

    public static function current(): ?self
    {
        return self::$current;
    }

    public static function setCurrent(self $pool): void
    {
        self::$current = $pool;
    }

    public function get(): object
    {
        return $this->inner->get();
    }

    public function put(object $pdo): void
    {
        $this->inner->put($pdo);
    }

    public function close(): void
    {
        $this->inner->close();
        self::$current = null;
    }
}
