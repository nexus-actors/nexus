<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Boot;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\ORMSetup;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Doctrine\Dbal\DoctrinePool;
use Monadial\Nexus\Doctrine\Dbal\Pool\ConnectionPool;
use Monadial\Nexus\Doctrine\Dbal\Pool\PoolConfig;
use Monadial\Nexus\Doctrine\Orm\Behavior\ActorSystemSpawner;
use Monadial\Nexus\Doctrine\Orm\Behavior\EntityRefFactory;
use Monadial\Nexus\Doctrine\Orm\Behavior\ReplayPolicy\CreateIfMissing;
use Monadial\Nexus\Doctrine\Orm\DoctrineEmPool;
use Monadial\Nexus\Doctrine\Orm\Pool\DefaultEntityManagerFactory;
use Monadial\Nexus\Doctrine\Orm\Pool\EmPoolConfig;
use Monadial\Nexus\Doctrine\Orm\Pool\EntityManagerPool;
use Monadial\Nexus\Example\TicTacToe\Actor\GameActor;
use Monadial\Nexus\Example\TicTacToe\Domain\Entity\GameSession;
use Monadial\Nexus\Runtime\Duration;
use Psr\Log\LoggerInterface;

/**
 * Per-worker Doctrine wiring — the only place Doctrine config touches the
 * app. Downstream code (game actor handler, HTTP handlers) receives
 * ready-to-use collaborators; nothing else knows about connection params
 * or ORM paths.
 */
final readonly class DoctrineKit
{
    public function __construct(
        public ConnectionPool $connPool,
        public EntityManagerPool $emPool,
        public EntityRefFactory $gameFactory,
    ) {}

    public static function build(DbConfig $db, ActorSystem $system, LoggerInterface $log): self
    {
        $connParams = $db->toDbalParams();

        $ormConfig = ORMSetup::createAttributeMetadataConfig(
            paths: [dirname(__DIR__) . '/Domain/Entity'],
        );
        $ormConfig->enableNativeLazyObjects(true);

        SchemaBootstrap::sync($connParams, $ormConfig);

        return new self(
            connPool: DoctrinePool::fromParams(
                name: 'tictactoe-dbal',
                connParams: $connParams,
                config: new PoolConfig(max: 8, minIdle: 1),
            ),
            emPool: DoctrineEmPool::forConfig(
                name: 'tictactoe-em',
                connParams: $connParams,
                ormSetup: $ormConfig,
                config: new EmPoolConfig(max: 8, minIdle: 1),
            ),
            gameFactory: EntityRefFactory::for(new ActorSystemSpawner($system), GameSession::class)
                ->using(new DefaultEntityManagerFactory($ormConfig))
                ->withConnectionSource(static fn(): Connection => DriverManager::getConnection($connParams))
                ->withReplayPolicy(new CreateIfMissing(
                    static fn(string $gameId): GameSession => new GameSession($gameId),
                ))
                ->withReceiveTimeout(Duration::seconds(300))
                ->handle(GameActor::handler($log))
                ->build(),
        );
    }
}
