<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Doctrine\Sql;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Monadial\Nexus\Observability\Observability;
use Override;
use SensitiveParameter;

/** @psalm-api */
final class TracingDriver extends AbstractDriverMiddleware
{
    public function __construct(
        Driver $driver,
        private readonly Observability $observability,
    ) {
        parent::__construct($driver);
    }

    #[Override]
    public function connect(
        #[SensitiveParameter]
        array $params,
    ): DriverConnection {
        return new TracingConnection(parent::connect($params), $this->observability);
    }
}
