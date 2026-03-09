<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Doctrine;

use Doctrine\DBAL\Connection;
use PDO;

/**
 * @psalm-api
 *
 * Stub — full Swoole PDOPool integration requires Swoole 6.0+.
 * This class is registered in the container only when ext-swoole is loaded.
 */
final class SwooleCoroutinePdoPool
{
    public function __construct(private readonly Connection $connection, private readonly int $size) {}

    public function get(): PDO
    {
        /** @psalm-suppress InternalMethod */
        $connection = $this->connection->getNativeConnection();
        assert($connection instanceof PDO);

        return $connection;
    }

    public function put(PDO $pdo): void
    {
        // No-op in stub — full pooling requires Swoole\Database\PDOPool
    }
}
