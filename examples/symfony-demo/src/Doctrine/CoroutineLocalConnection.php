<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\PDO\Connection as PDOConnection;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/**
 * DBAL DriverConnection that holds a coroutine-local PDO borrowed from SwoolePDOPool.
 *
 * When first used inside a Swoole coroutine, a PDO connection is borrowed from
 * the pool. Swoole::defer() returns it when the coroutine ends.
 * Outside coroutines (CLI, tests) the wrapped driver's connect() is used as fallback.
 *
 * Transactions: since each coroutine gets its own PDO, transactions are naturally
 * isolated — no cross-coroutine transaction contamination.
 */
final class CoroutineLocalConnection implements Connection
{
    /** @var array<int, PDOConnection> */
    private array $localConnections = [];

    /** @var array<int, object> */
    private array $localPdos = [];

    public function __construct(private readonly SwoolePDOPool $pool) {}

    private function inner(): PDOConnection
    {
        $cid = \Swoole\Coroutine::getCid();
        $key = $cid === -1 ? 0 : $cid;

        if (!isset($this->localConnections[$key])) {
            $pdo                          = $this->pool->get();
            $this->localPdos[$key]        = $pdo;
            /** @var \PDO $pdoTyped */
            $pdoTyped                     = $pdo;
            $this->localConnections[$key] = new PDOConnection($pdoTyped);

            if ($cid !== -1) {
                \Swoole\Coroutine::defer(function () use ($key): void {
                    if (isset($this->localPdos[$key])) {
                        $this->pool->put($this->localPdos[$key]);
                        unset($this->localPdos[$key], $this->localConnections[$key]);
                    }
                });
            }
        }

        return $this->localConnections[$key];
    }

    public function prepare(string $sql): Statement
    {
        return $this->inner()->prepare($sql);
    }

    public function query(string $sql): Result
    {
        return $this->inner()->query($sql);
    }

    public function quote(mixed $value): string
    {
        return $this->inner()->quote($value);
    }

    public function exec(string $sql): int
    {
        return $this->inner()->exec($sql);
    }

    public function lastInsertId(): int|string
    {
        return $this->inner()->lastInsertId();
    }

    public function beginTransaction(): void
    {
        $this->inner()->beginTransaction();
    }

    public function commit(): void
    {
        $this->inner()->commit();
    }

    public function rollBack(): void
    {
        $this->inner()->rollBack();
    }

    public function getNativeConnection(): mixed
    {
        return $this->inner()->getNativeConnection();
    }

    public function getServerVersion(): string
    {
        return $this->inner()->getServerVersion();
    }
}
