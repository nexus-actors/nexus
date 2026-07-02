<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Http;

use Doctrine\DBAL\Connection;
use Monadial\Nexus\Doctrine\Dbal\Pool\ConnectionPool;

/** @psalm-api */
final class ConnectionLease
{
    private ?Connection $conn = null;
    private bool $poisoned = false;

    public function __construct(private readonly ConnectionPool $pool) {}

    public function get(): Connection
    {
        return $this->conn ??= $this->pool->take();
    }

    public function poison(): void
    {
        $this->poisoned = true;
    }

    public function release(): void
    {
        if ($this->conn === null) {
            return;
        }

        $this->pool->release($this->conn, poison: $this->poisoned);
        $this->conn = null;
    }
}
