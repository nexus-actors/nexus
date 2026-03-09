<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * DBAL Driver that replaces the real connect() with a coroutine-local pooled
 * connection when a SwoolePDOPool is available.
 *
 * Falls back to the wrapped driver when outside a coroutine (CLI, tests).
 */
final class SwoolePoolDriver extends AbstractDriverMiddleware
{
    public function connect(array $params): Connection
    {
        $pool = SwoolePDOPool::current();
        $cid  = \Swoole\Coroutine::getCid();

        if ($pool === null || $cid === -1) {
            return parent::connect($params);
        }

        return new CoroutineLocalConnection($pool);
    }
}
