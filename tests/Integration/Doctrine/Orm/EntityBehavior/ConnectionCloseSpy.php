<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Doctrine\Orm\EntityBehavior;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Override;

/**
 * DBAL `Connection` subclass that counts `close()` calls. Inherits the
 * full Connection machinery (queries, transactions, platform discovery,
 * etc.) by sharing the underlying driver/params with the parent, so it
 * works as a drop-in `Connection` for tests that need real query
 * execution AND a `close()` spy.
 *
 * Only `close()` is overridden — every other method falls through to
 * the parent's normal implementation.
 */
final class ConnectionCloseSpy extends Connection
{
    public int $closeCalls = 0;

    /**
     * @param array<string, mixed> $params
     */
    public function __construct(array $params, Driver $driver)
    {
        parent::__construct($params, $driver, new Configuration());
    }

    #[Override]
    public function close(): void
    {
        $this->closeCalls++;

        parent::close();
    }
}
