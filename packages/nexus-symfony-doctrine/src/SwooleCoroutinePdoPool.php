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
    public function __construct(
        private readonly Connection $connection,
        private readonly int $size,
    ) {}

    /**
     * @psalm-suppress InvalidReturnType, InvalidReturnStatement
     */
    public function get(): PDO
    {
        /** @psalm-suppress InternalMethod */
        return $this->connection->getNativeConnection();
    }

    public function put(PDO $pdo): void
    {
        // No-op in stub — full pooling requires Swoole\Database\PDOPool
    }
}
