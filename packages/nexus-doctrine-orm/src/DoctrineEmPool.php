<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\Configuration;
use Monadial\Nexus\Doctrine\Dbal\Pool\Channel\Channel;
use Monadial\Nexus\Doctrine\Dbal\Pool\Channel\FiberChannel;
use Monadial\Nexus\Doctrine\Dbal\Pool\Channel\SwooleChannel;
use Monadial\Nexus\Doctrine\Dbal\Pool\ConnectionPool;
use Monadial\Nexus\Doctrine\Dbal\Pool\DriverManagerConnectionFactory;
use Monadial\Nexus\Doctrine\Dbal\Pool\PoolConfig;
use Monadial\Nexus\Doctrine\Orm\Pool\DefaultEntityManagerFactory;
use Monadial\Nexus\Doctrine\Orm\Pool\EmPoolConfig;
use Monadial\Nexus\Doctrine\Orm\Pool\EntityManagerPool;
use Monadial\Nexus\Doctrine\Orm\Pool\PooledEntityManager;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @psalm-api
 *
 * @psalm-import-type Params from \Doctrine\DBAL\DriverManager
 */
final class DoctrineEmPool
{
    /**
     * @param Params $connParams
     */
    public static function forConfig(
        string $name,
        array $connParams,
        Configuration $ormSetup,
        ?EmPoolConfig $config = null,
        ?EventDispatcherInterface $events = null,
        ?LoggerInterface $logger = null,
    ): EntityManagerPool {
        $config ??= new EmPoolConfig();
        $log = $logger ?? new NullLogger();

        /** @var Channel<Connection> $connChannel */
        $connChannel = self::channel($config->max);

        $connPool = new ConnectionPool(
            name: $name . '-conn',
            factory: new DriverManagerConnectionFactory($connParams),
            config: new PoolConfig(max: $config->max, minIdle: 0),
            channel: $connChannel,
            events: $events,
            logger: $log,
        );

        /** @var Channel<PooledEntityManager> $emChannel */
        $emChannel = self::channel($config->max);

        return new EntityManagerPool(
            name: $name,
            factory: new DefaultEntityManagerFactory($ormSetup),
            connPool: $connPool,
            config: $config,
            channel: $emChannel,
            events: $events,
            logger: $log,
        );
    }

    private static function channel(int $capacity): Channel
    {
        return extension_loaded('swoole')
            ? new SwooleChannel($capacity)
            : new FiberChannel($capacity);
    }
}
