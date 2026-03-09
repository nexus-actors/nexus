<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;

/**
 * DBAL Middleware that wraps the database driver with Swoole coroutine-safe
 * connection pooling via SwoolePDOPool.
 *
 * Registered in config/packages/doctrine.yaml as a DBAL middleware.
 * Autowired by Symfony (no explicit service definition needed since
 * App\Doctrine\SwoolePoolMiddleware is auto-discovered).
 */
final class SwoolePoolMiddleware implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new SwoolePoolDriver($driver);
    }
}
