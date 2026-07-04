<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Tests\Integration\Inventory;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Doctrine\Dbal\Pool\Channel\Channel;
use Monadial\Nexus\Doctrine\Dbal\Pool\Channel\FiberChannel;
use Monadial\Nexus\Doctrine\Dbal\Pool\ConnectionPool;
use Monadial\Nexus\Doctrine\Dbal\Pool\DriverManagerConnectionFactory;
use Monadial\Nexus\Doctrine\Dbal\Pool\PoolConfig;
use Monadial\Nexus\Doctrine\Orm\Pool\DefaultEntityManagerFactory;
use Monadial\Nexus\Doctrine\Orm\Pool\EmPoolConfig;
use Monadial\Nexus\Doctrine\Orm\Pool\EntityManagerPool;
use Monadial\Nexus\Doctrine\Orm\Pool\PooledEntityManager;
use Monadial\Nexus\Example\Fulfillment\Inventory\Infrastructure\ReadModel\InventoryLevel;
use Monadial\Nexus\Example\Fulfillment\Inventory\Infrastructure\ReadModel\InventoryLevelsProjector;
use Monadial\Nexus\Example\Fulfillment\Inventory\Infrastructure\ReadModel\InventoryReadModel;
use Monadial\Nexus\Example\Fulfillment\Platform\Bus\ContextBusActor;
use Monadial\Nexus\Example\Fulfillment\Platform\Bus\Subscribe;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Bus\Publish;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\Restocked;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\StockReleased;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\StockReservationRejected;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\StockReserved;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderId;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Quantity;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Sku;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\TenantId;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function dirname;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

#[CoversClass(InventoryLevelsProjector::class)]
#[CoversClass(InventoryReadModel::class)]
#[CoversClass(InventoryLevel::class)]
final class InventoryProjectionTest extends TestCase
{
    /**
     * @return array{EntityManagerPool, array{driver: 'pdo_sqlite', path: string}, \Doctrine\ORM\Configuration, string}
     */
    private function bootPool(string $prefix): array
    {
        $dbPath = tempnam(sys_get_temp_dir(), $prefix);
        self::assertIsString($dbPath);
        $connParams = ['driver' => 'pdo_sqlite', 'path' => $dbPath];

        $ormConfig = ORMSetup::createAttributeMetadataConfig(
            paths: [dirname(__DIR__, 3) . '/src/Inventory/Infrastructure/ReadModel'],
        );
        $ormConfig->enableNativeLazyObjects(true);

        /** @var Channel<Connection> $connChannel */
        $connChannel = new FiberChannel(2);
        /** @var Channel<PooledEntityManager> $emChannel */
        $emChannel = new FiberChannel(2);

        $pool = new EntityManagerPool(
            name: 'inventory-levels-test',
            factory: new DefaultEntityManagerFactory($ormConfig),
            connPool: new ConnectionPool(
                name: 'inventory-levels-test-conn',
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
        new SchemaTool($schemaEm)->createSchema([$schemaEm->getClassMetadata(InventoryLevel::class)]);
        $pool->release($schemaEm);

        return [$pool, $connParams, $ormConfig, $dbPath];
    }

    #[Test]
    public function restockReserveReleaseFoldIntoLevelMath(): void
    {
        [$pool, $connParams, $ormConfig, $dbPath] = $this->bootPool('nexus-inventory-levels-math-');

        $tenantId = TenantId::fromString('acme');
        $sku = Sku::fromString('WIDGET-42');
        $order1 = OrderId::generate();
        $order2 = OrderId::generate();

        $runtime = new FiberRuntime();
        $system = ActorSystem::create('inventory-projection-test', $runtime);

        $bus = $system->spawn(Props::fromBehavior(ContextBusActor::behavior()), 'context-bus');
        $projector = $system->spawn(
            Props::fromBehavior(InventoryLevelsProjector::behavior(new InventoryReadModel($pool))),
            InventoryLevelsProjector::ACTOR_NAME,
        );

        $bus->tell(new Subscribe($projector));
        $bus->tell(new Publish(new Restocked($tenantId, $sku, Quantity::of(10))));
        $bus->tell(new Publish(new StockReserved($tenantId, $sku, $order1, Quantity::of(3))));
        $bus->tell(new Publish(new StockReserved($tenantId, $sku, $order2, Quantity::of(2))));
        $bus->tell(new Publish(new StockReleased($tenantId, $sku, $order1, Quantity::of(3))));
        // rejected event is a no-op skip
        $bus->tell(new Publish(new StockReservationRejected($tenantId, $sku, $order2, Quantity::of(99), 5, 'insufficient stock')));

        $runtime->scheduleOnce(Duration::millis(500), static function () use ($system): void {
            $system->shutdown(Duration::seconds(1));
        });
        $system->run();

        $verifyEm = new DefaultEntityManagerFactory($ormConfig)->create(DriverManager::getConnection($connParams));

        $rows = $verifyEm->getRepository(InventoryLevel::class)->findAll();
        self::assertCount(1, $rows);

        $row = $verifyEm->find(InventoryLevel::class, ['sku' => 'WIDGET-42', 'tenantId' => 'acme']);
        self::assertNotNull($row);
        self::assertSame(10, $row->onHand);
        self::assertSame(2, $row->reserved);
        self::assertSame(8, $row->available());

        $verifyEm->getConnection()->close();
        $pool->close(Duration::seconds(1));
        unlink($dbPath);
    }

    #[Test]
    public function duplicateDeliveryIsIdempotent(): void
    {
        [$pool, $connParams, $ormConfig, $dbPath] = $this->bootPool('nexus-inventory-levels-idem-');

        $tenantId = TenantId::fromString('acme');
        $sku = Sku::fromString('WIDGET-42');
        $order1 = OrderId::generate();

        $runtime = new FiberRuntime();
        $system = ActorSystem::create('inventory-idem-test', $runtime);

        $bus = $system->spawn(Props::fromBehavior(ContextBusActor::behavior()), 'context-bus');
        $projector = $system->spawn(
            Props::fromBehavior(InventoryLevelsProjector::behavior(new InventoryReadModel($pool))),
            InventoryLevelsProjector::ACTOR_NAME,
        );

        $bus->tell(new Subscribe($projector));
        $bus->tell(new Publish(new Restocked($tenantId, $sku, Quantity::of(10))));
        $bus->tell(new Publish(new StockReserved($tenantId, $sku, $order1, Quantity::of(4))));
        // duplicate delivery of the same reservation — must not double-count
        $bus->tell(new Publish(new StockReserved($tenantId, $sku, $order1, Quantity::of(4))));

        $runtime->scheduleOnce(Duration::millis(500), static function () use ($system): void {
            $system->shutdown(Duration::seconds(1));
        });
        $system->run();

        $verifyEm = new DefaultEntityManagerFactory($ormConfig)->create(DriverManager::getConnection($connParams));

        $row = $verifyEm->find(InventoryLevel::class, ['sku' => 'WIDGET-42', 'tenantId' => 'acme']);
        self::assertNotNull($row);
        self::assertSame(10, $row->onHand);
        self::assertSame(4, $row->reserved);
        self::assertSame(6, $row->available());

        $verifyEm->getConnection()->close();
        $pool->close(Duration::seconds(1));
        unlink($dbPath);
    }

    #[Test]
    public function sameSkuUnderDifferentTenantsProducesDistinctRows(): void
    {
        [$pool, $connParams, $ormConfig, $dbPath] = $this->bootPool('nexus-inventory-levels-isolation-');

        $sku = Sku::fromString('WIDGET-42');
        $acme = TenantId::fromString('acme');
        $umbrella = TenantId::fromString('umbrella');

        $runtime = new FiberRuntime();
        $system = ActorSystem::create('inventory-isolation-test', $runtime);

        $bus = $system->spawn(Props::fromBehavior(ContextBusActor::behavior()), 'context-bus');
        $projector = $system->spawn(
            Props::fromBehavior(InventoryLevelsProjector::behavior(new InventoryReadModel($pool))),
            InventoryLevelsProjector::ACTOR_NAME,
        );

        $bus->tell(new Subscribe($projector));
        $bus->tell(new Publish(new Restocked($acme, $sku, Quantity::of(5))));
        $bus->tell(new Publish(new Restocked($umbrella, $sku, Quantity::of(9))));

        $runtime->scheduleOnce(Duration::millis(500), static function () use ($system): void {
            $system->shutdown(Duration::seconds(1));
        });
        $system->run();

        $verifyEm = new DefaultEntityManagerFactory($ormConfig)->create(DriverManager::getConnection($connParams));

        $rows = $verifyEm->getRepository(InventoryLevel::class)->findAll();
        self::assertCount(2, $rows);

        $acmeRow = $verifyEm->find(InventoryLevel::class, ['sku' => 'WIDGET-42', 'tenantId' => 'acme']);
        self::assertNotNull($acmeRow);
        self::assertSame(5, $acmeRow->onHand);

        $umbrellaRow = $verifyEm->find(InventoryLevel::class, ['sku' => 'WIDGET-42', 'tenantId' => 'umbrella']);
        self::assertNotNull($umbrellaRow);
        self::assertSame(9, $umbrellaRow->onHand);

        $verifyEm->getConnection()->close();
        $pool->close(Duration::seconds(1));
        unlink($dbPath);
    }
}
