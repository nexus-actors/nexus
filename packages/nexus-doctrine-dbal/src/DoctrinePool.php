<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal;

use Doctrine\DBAL\Connection;
use Monadial\Nexus\Doctrine\Dbal\Pool\Channel\Channel;
use Monadial\Nexus\Doctrine\Dbal\Pool\Channel\FiberChannel;
use Monadial\Nexus\Doctrine\Dbal\Pool\Channel\SwooleChannel;
use Monadial\Nexus\Doctrine\Dbal\Pool\ConnectionPool;
use Monadial\Nexus\Doctrine\Dbal\Pool\DriverManagerConnectionFactory;
use Monadial\Nexus\Doctrine\Dbal\Pool\PoolConfig;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @psalm-api
 *
 * @psalm-import-type Params from \Doctrine\DBAL\DriverManager
 */
final class DoctrinePool
{
    /**
     * @param Params $connParams DBAL connection parameters (e.g. driver, host, dbname).
     */
    public static function fromParams(
        string $name,
        array $connParams,
        ?PoolConfig $config = null,
        ?EventDispatcherInterface $events = null,
        ?LoggerInterface $logger = null,
    ): ConnectionPool {
        $config ??= new PoolConfig();
        $factory = new DriverManagerConnectionFactory($connParams);

        /** @var Channel<Connection> $channel fresh, empty channel — the item type is fixed by this pool */
        $channel = extension_loaded('swoole')
            ? new SwooleChannel($config->max)
            : new FiberChannel($config->max);

        return new ConnectionPool(
            name: $name,
            factory: $factory,
            config: $config,
            channel: $channel,
            events: $events,
            logger: $logger ?? new NullLogger(),
        );
    }
}
