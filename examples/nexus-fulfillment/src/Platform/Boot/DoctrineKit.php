<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Platform\Boot;

use Doctrine\ORM\ORMSetup;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Doctrine\Dbal\DoctrinePool;
use Monadial\Nexus\Doctrine\Dbal\Pool\ConnectionPool;
use Monadial\Nexus\Doctrine\Dbal\Pool\PoolConfig;
use Monadial\Nexus\Doctrine\Orm\DoctrineEmPool;
use Monadial\Nexus\Doctrine\Orm\Pool\EmPoolConfig;
use Monadial\Nexus\Doctrine\Orm\Pool\EntityManagerPool;
use Monadial\Nexus\Example\Fulfillment\Platform\Persistence\PooledDoctrineEventStore;
use Monadial\Nexus\Example\Fulfillment\Platform\Persistence\PooledDoctrineSnapshotStore;
use Monadial\Nexus\Example\Fulfillment\Platform\Serialization\MessageTypes;
use Monadial\Nexus\Persistence\Doctrine\Entity\EventEntry;
use Monadial\Nexus\Persistence\Event\EventStore;
use Monadial\Nexus\Persistence\Snapshot\SnapshotStore;
use Monadial\Nexus\Serialization\ValinorMessageSerializer;
use Psr\Log\LoggerInterface;
use ReflectionClass;

use function dirname;

/**
 * Per-worker persistence wiring — the only place persistence config touches
 * the app. Downstream code receives ready-to-use collaborators.
 *
 * The journal and snapshot tables are created idempotently at worker startup
 * via {@see SchemaBootstrap}. Both stores borrow from the shared EM pool per
 * operation so no connection is pinned while an actor sits idle.
 */
final readonly class DoctrineKit
{
    public function __construct(
        public ConnectionPool $connPool,
        public EntityManagerPool $emPool,
        public EventStore $eventStore,
        public SnapshotStore $snapshotStore,
    ) {}

    public static function build(DbConfig $db, ActorSystem $system, LoggerInterface $log): self
    {
        $connParams = $db->toConnectionParams();

        // Resolve the journal entity directory by reflection — stays correct
        // regardless of where the package is mounted.
        $journalPath = dirname((string) new ReflectionClass(EventEntry::class)->getFileName());

        $ormConfig = ORMSetup::createAttributeMetadataConfig(
            paths: [$journalPath],
        );
        $ormConfig->enableNativeLazyObjects(true);

        SchemaBootstrap::sync($connParams, $ormConfig);

        $connPool = DoctrinePool::fromParams(
            name: 'fulfillment-dbal',
            connParams: $connParams,
            config: new PoolConfig(max: 8, minIdle: 1),
        );

        $emPool = DoctrineEmPool::forConfig(
            name: 'fulfillment-em',
            connParams: $connParams,
            ormSetup: $ormConfig,
            config: new EmPoolConfig(max: 8, minIdle: 1),
        );

        $serializer = new ValinorMessageSerializer(MessageTypes::registry());

        return new self(
            connPool: $connPool,
            emPool: $emPool,
            eventStore: new PooledDoctrineEventStore($emPool, $serializer),
            snapshotStore: new PooledDoctrineSnapshotStore($emPool, $serializer),
        );
    }
}
