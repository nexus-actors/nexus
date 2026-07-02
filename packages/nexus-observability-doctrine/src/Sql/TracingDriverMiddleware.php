<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Doctrine\Sql;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;
use Monadial\Nexus\Observability\Observability;
use Override;

/**
 * @psalm-api
 *
 * Doctrine DBAL middleware that opens a Client span for each executed SQL
 * statement, carrying the parameterized query text (never bound values).
 * Add via `Configuration::setMiddlewares([...])`.
 */
final class TracingDriverMiddleware implements Middleware
{
    public function __construct(
        private readonly Observability $observability,
    ) {}

    #[Override]
    public function wrap(Driver $driver): Driver
    {
        return new TracingDriver($driver, $this->observability);
    }
}
