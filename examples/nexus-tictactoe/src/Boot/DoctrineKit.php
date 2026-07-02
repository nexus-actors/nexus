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
use Monadial\Nexus\Example\TicTacToe\Persistence\PooledDoctrineEventStore;
use Monadial\Nexus\Example\TicTacToe\ReadModel\DoctrineGameReadModel;
use Monadial\Nexus\Persistence\Doctrine\Entity\EventEntry;
use Psr\Log\LoggerInterface;
use ReflectionClass;

use function dirname;

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
 * The journal is durable: a {@see PooledDoctrineEventStore} writes events to
 * the `nexus_event_journal` table (borrowing a pooled EM per operation, so no
 * connection is pinned per game). The `games` table remains the lobby read
 * model, kept current by the actor's projection. Both share the one EM pool.
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

        // Map both the app's read-model entity (GameSession) AND the framework's
        // event-journal entity (EventEntry), so SchemaBootstrap creates the
        // `games` table and the `nexus_event_journal` table in one pass. The
        // journal path is resolved by reflection to stay independent of where
        // the package is mounted.
        $journalPath = dirname((string) new ReflectionClass(EventEntry::class)->getFileName());

        $ormConfig = ORMSetup::createAttributeMetadataConfig(
            paths: [dirname(__DIR__) . '/Domain/Entity', $journalPath],
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
                new PooledDoctrineEventStore($emPool),
                new DoctrineGameReadModel($emPool),
                $log,
            ),
        );
    }
}
