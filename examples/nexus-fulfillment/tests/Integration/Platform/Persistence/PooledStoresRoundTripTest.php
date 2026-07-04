<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Tests\Integration\Platform\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Monadial\Nexus\Doctrine\Dbal\Pool\Channel\Channel;
use Monadial\Nexus\Doctrine\Dbal\Pool\Channel\FiberChannel;
use Monadial\Nexus\Doctrine\Dbal\Pool\ConnectionPool;
use Monadial\Nexus\Doctrine\Dbal\Pool\DriverManagerConnectionFactory;
use Monadial\Nexus\Doctrine\Dbal\Pool\PoolConfig;
use Monadial\Nexus\Doctrine\Orm\Pool\DefaultEntityManagerFactory;
use Monadial\Nexus\Doctrine\Orm\Pool\EmPoolConfig;
use Monadial\Nexus\Doctrine\Orm\Pool\EntityManagerPool;
use Monadial\Nexus\Doctrine\Orm\Pool\PooledEntityManager;
use Monadial\Nexus\Example\Fulfillment\Orders\Domain\Order;
use Monadial\Nexus\Example\Fulfillment\Orders\Domain\OrderStatus;
use Monadial\Nexus\Example\Fulfillment\Platform\Persistence\PooledDoctrineEventStore;
use Monadial\Nexus\Example\Fulfillment\Platform\Persistence\PooledDoctrineSnapshotStore;
use Monadial\Nexus\Example\Fulfillment\Platform\Serialization\MessageTypes;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Orders\OrderPlaced;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Money;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderId;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderLine;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Quantity;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Sku;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\TenantId;
use Monadial\Nexus\Persistence\Doctrine\Entity\EventEntry;
use Monadial\Nexus\Persistence\Doctrine\Entity\SnapshotEntry;
use Monadial\Nexus\Persistence\Event\EventEnvelope;
use Monadial\Nexus\Persistence\PersistenceId;
use Monadial\Nexus\Persistence\Snapshot\SnapshotEnvelope;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Serialization\ValinorMessageSerializer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Uid\Ulid;

use function dirname;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

#[CoversClass(PooledDoctrineEventStore::class)]
#[CoversClass(PooledDoctrineSnapshotStore::class)]
final class PooledStoresRoundTripTest extends TestCase
{
    #[Test]
    public function eventSurvivesJournalRoundTripWithWireTypeName(): void
    {
        $dbPath = tempnam(sys_get_temp_dir(), 'nexus-stores-rt-');
        self::assertIsString($dbPath);
        $connParams = ['driver' => 'pdo_sqlite', 'path' => $dbPath];

        $pool = $this->buildPool($connParams);

        $registry = MessageTypes::registry();
        $serializer = new ValinorMessageSerializer($registry);
        $store = new PooledDoctrineEventStore($pool, $serializer, $registry);

        $pid = PersistenceId::of('Order', 'test-event-rt');
        $tenantId = TenantId::fromString('acme');
        $orderId = OrderId::generate();
        $lines = [new OrderLine(Sku::fromString('WIDGET-42'), Quantity::of(2), Money::of(1999, 'EUR'))];
        $event = new OrderPlaced($tenantId, $orderId, $lines, Money::of(3998, 'EUR'));
        $writerId = new Ulid();

        // Envelope arrives from PersistenceEngine with FQCN as eventType.
        $envelope = new EventEnvelope(
            persistenceId: $pid,
            sequenceNr: 1,
            event: $event,
            eventType: OrderPlaced::class,
            timestamp: new DateTimeImmutable(),
            writerId: $writerId,
        );

        $store->persist($pid, $envelope);

        // (i) load() returns the event with values intact.
        $loaded = $store->load($pid);
        self::assertCount(1, $loaded);
        $loadedEnvelope = $loaded[0];
        self::assertInstanceOf(OrderPlaced::class, $loadedEnvelope->event);
        self::assertTrue($loadedEnvelope->event->orderId->equals($orderId));
        self::assertTrue($loadedEnvelope->event->total->equals(Money::of(3998, 'EUR')));

        // (ii) raw SQL on the journal table shows the wire name, not the FQCN.
        $rawConn = DriverManager::getConnection($connParams);
        $storedType = $rawConn->fetchOne(
            'SELECT event_type FROM nexus_event_journal WHERE persistence_id = ?',
            [$pid->toString()],
        );
        $rawConn->close();
        self::assertSame('orders.order_placed.v1', $storedType);

        $pool->close(Duration::seconds(1));
        unlink($dbPath);
    }

    #[Test]
    public function snapshotSurvivesStoreRoundTripWithWireTypeName(): void
    {
        $dbPath = tempnam(sys_get_temp_dir(), 'nexus-stores-rt-snap-');
        self::assertIsString($dbPath);
        $connParams = ['driver' => 'pdo_sqlite', 'path' => $dbPath];

        $pool = $this->buildPool($connParams);

        $registry = MessageTypes::registry();
        $serializer = new ValinorMessageSerializer($registry);
        $store = new PooledDoctrineSnapshotStore($pool, $serializer, $registry);

        $pid = PersistenceId::of('Order', 'test-snapshot-rt');
        $tenantId = TenantId::fromString('acme');
        $orderId = OrderId::generate();
        $lines = [new OrderLine(Sku::fromString('WIDGET-42'), Quantity::of(2), Money::of(1999, 'EUR'))];
        $state = new Order($tenantId, $orderId, OrderStatus::Placed, $lines, Money::of(3998, 'EUR'), null);
        $writerId = new Ulid();

        // Snapshot envelope arrives from PersistenceEngine with FQCN as stateType.
        $snapshot = new SnapshotEnvelope(
            persistenceId: $pid,
            sequenceNr: 10,
            state: $state,
            stateType: Order::class,
            timestamp: new DateTimeImmutable(),
            writerId: $writerId,
        );

        $store->save($pid, $snapshot);

        // load() returns state with status intact.
        $loaded = $store->load($pid);
        self::assertNotNull($loaded);
        self::assertInstanceOf(Order::class, $loaded->state);
        self::assertSame(OrderStatus::Placed, $loaded->state->status);
        self::assertSame('orders.order_state.v1', $loaded->stateType);

        $pool->close(Duration::seconds(1));
        unlink($dbPath);
    }

    /**
     * @param array{driver: string, path: string} $connParams
     */
    private function buildPool(array $connParams): EntityManagerPool
    {
        $journalPath = dirname((string) new ReflectionClass(EventEntry::class)->getFileName());

        $ormConfig = ORMSetup::createAttributeMetadataConfig(paths: [$journalPath]);
        $ormConfig->enableNativeLazyObjects(true);

        /** @var Channel<Connection> $connChannel */
        $connChannel = new FiberChannel(2);
        /** @var Channel<PooledEntityManager> $emChannel */
        $emChannel = new FiberChannel(2);

        $pool = new EntityManagerPool(
            name: 'stores-rt-test',
            factory: new DefaultEntityManagerFactory($ormConfig),
            connPool: new ConnectionPool(
                name: 'stores-rt-test-conn',
                factory: new DriverManagerConnectionFactory($connParams),
                config: new PoolConfig(max: 2, minIdle: 0),
                channel: $connChannel,
            ),
            config: new EmPoolConfig(
                clearOnReturn: true,
                max: 2,
                minIdle: 0,
            ),
            channel: $emChannel,
        );

        $schemaEm = $pool->take();

        new SchemaTool($schemaEm)->createSchema([
            $schemaEm->getClassMetadata(EventEntry::class),
            $schemaEm->getClassMetadata(SnapshotEntry::class),
        ]);

        $pool->release($schemaEm);

        return $pool;
    }
}
