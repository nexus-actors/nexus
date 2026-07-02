<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Boot;

use Doctrine\ORM\ORMSetup;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Doctrine\Dbal\DoctrinePool;
use Monadial\Nexus\Doctrine\Dbal\Pool\ConnectionPool;
use Monadial\Nexus\Doctrine\Dbal\Pool\PoolConfig;
use Monadial\Nexus\Doctrine\Orm\DoctrineEmPool;
use Monadial\Nexus\Doctrine\Orm\Pool\EmPoolConfig;
use Monadial\Nexus\Doctrine\Orm\Pool\EntityManagerPool;
use Monadial\Nexus\Example\TicTacToe\Actor\GameRefFactory;
use Monadial\Nexus\Example\TicTacToe\ReadModel\DoctrineGameReadModel;
use Monadial\Nexus\Persistence\Event\InMemoryEventStore;
use Psr\Log\LoggerInterface;

/**
 * Per-worker persistence wiring — the only place persistence config touches
 * the app. Downstream code receives ready-to-use collaborators.
 *
 * Two sides, CQRS-style:
 *  - `connPool` / `emPool` — pooled DBAL/ORM handles for the stateless HTTP
 *    lobby AND for the read-model projection (borrowed per write, never held).
 *  - `gameFactory` — the event-sourced write side: one {@see GameRefFactory}
 *    spawning a per-id game actor whose events are the source of truth.
 *
 * The journal is a per-worker {@see InMemoryEventStore} for the demo. Swap it
 * for `DbalEventStore` (nexus-persistence-dbal) to persist events durably —
 * the actor code is identical either way. The `games` table remains the
 * lobby read model, kept current by the actor's projection.
 */
final readonly class DoctrineKit
{
    public function __construct(
        public ConnectionPool $connPool,
        public EntityManagerPool $emPool,
        public GameRefFactory $gameFactory,
    ) {}

    public static function build(DbConfig $db, ActorSystem $system, LoggerInterface $log): self
    {
        $connParams = $db->toDbalParams();

        $ormConfig = ORMSetup::createAttributeMetadataConfig(
            paths: [dirname(__DIR__) . '/Domain/Entity'],
        );
        $ormConfig->enableNativeLazyObjects(true);

        SchemaBootstrap::sync($connParams, $ormConfig);

        $connPool = DoctrinePool::fromParams(
            name: 'tictactoe-dbal',
            connParams: $connParams,
            config: new PoolConfig(max: 8, minIdle: 1),
        );

        $emPool = DoctrineEmPool::forConfig(
            name: 'tictactoe-em',
            connParams: $connParams,
            ormSetup: $ormConfig,
            config: new EmPoolConfig(max: 8, minIdle: 1),
        );

        return new self(
            connPool: $connPool,
            emPool: $emPool,
            gameFactory: new GameRefFactory(
                $system,
                new InMemoryEventStore(),
                new DoctrineGameReadModel($emPool),
                $log,
            ),
        );
    }
}
