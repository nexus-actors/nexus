<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Tests\Integration\Orders;

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
use Monadial\Nexus\Example\Fulfillment\Orders\Infrastructure\ReadModel\OrdersReadModel;
use Monadial\Nexus\Example\Fulfillment\Orders\Infrastructure\ReadModel\OrdersViewProjector;
use Monadial\Nexus\Example\Fulfillment\Orders\Infrastructure\ReadModel\OrderView;
use Monadial\Nexus\Example\Fulfillment\Platform\Bus\ContextBusActor;
use Monadial\Nexus\Example\Fulfillment\Platform\Bus\Subscribe;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Bus\Publish;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Orders\OrderCancelled;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Orders\OrderPlaced;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Money;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderId;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderLine;
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

#[CoversClass(OrdersViewProjector::class)]
#[CoversClass(OrdersReadModel::class)]
#[CoversClass(OrderView::class)]
final class OrdersProjectionTest extends TestCase
{
    #[Test]
    public function busEventsAreFoldedIntoOrdersView(): void
    {
        // File-backed sqlite: :memory: databases are per-connection, and the
        // EM pool opens several — mirror the monorepo's pool-test idiom.
        $dbPath = tempnam(sys_get_temp_dir(), 'nexus-orders-view-test-');
        self::assertIsString($dbPath);
        $connParams = ['driver' => 'pdo_sqlite', 'path' => $dbPath];

        $ormConfig = ORMSetup::createAttributeMetadataConfig(
            paths: [dirname(__DIR__, 3) . '/src/Orders/Infrastructure/ReadModel'],
        );
        $ormConfig->enableNativeLazyObjects(true);

        // Built by hand instead of DoctrineEmPool::forConfig — the factory
        // picks a Swoole channel whenever ext-swoole is loaded, and Swoole
        // channels abort the process outside a coroutine. This test runs on
        // FiberRuntime, so pin FiberChannel (what forConfig resolves to in
        // a Swoole-free container).
        /** @var Channel<Connection> $connChannel */
        $connChannel = new FiberChannel(2);
        /** @var Channel<PooledEntityManager> $emChannel */
        $emChannel = new FiberChannel(2);

        $pool = new EntityManagerPool(
            name: 'orders-view-test',
            factory: new DefaultEntityManagerFactory($ormConfig),
            connPool: new ConnectionPool(
                name: 'orders-view-test-conn',
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
        new SchemaTool($schemaEm)->createSchema([$schemaEm->getClassMetadata(OrderView::class)]);
        $pool->release($schemaEm);

        $tenantId = TenantId::fromString('acme');
        $orderId = OrderId::generate();
        $lines = [
            new OrderLine(Sku::fromString('WIDGET-01'), Quantity::of(2), Money::of(1999, 'EUR')),
        ];

        $runtime = new FiberRuntime();
        $system = ActorSystem::create('orders-projection-test', $runtime);

        $bus = $system->spawn(Props::fromBehavior(ContextBusActor::behavior()), 'context-bus');
        $projector = $system->spawn(
            Props::fromBehavior(OrdersViewProjector::behavior(new OrdersReadModel($pool))),
            OrdersViewProjector::ACTOR_NAME,
        );

        $bus->tell(new Subscribe($projector));
        $bus->tell(new Publish(new OrderPlaced($tenantId, $orderId, $lines, Money::of(3998, 'EUR'))));
        // duplicate delivery — proves upsert idempotency
        $bus->tell(new Publish(new OrderPlaced($tenantId, $orderId, $lines, Money::of(3998, 'EUR'))));
        $bus->tell(new Publish(new OrderCancelled($tenantId, $orderId, 'customer-request')));

        $runtime->scheduleOnce(Duration::millis(500), static function () use ($system): void {
            $system->shutdown(Duration::seconds(1));
        });
        $system->run();

        // Assert through a plain EM on a fresh connection — proves the rows
        // hit the database, not just a pooled identity map.
        $verifyEm = new DefaultEntityManagerFactory($ormConfig)->create(DriverManager::getConnection($connParams));

        $rows = $verifyEm->getRepository(OrderView::class)->findAll();
        self::assertCount(1, $rows);

        $row = $verifyEm->find(OrderView::class, $orderId->value);
        self::assertNotNull($row);
        self::assertSame('cancelled', $row->status);
        self::assertSame('acme', $row->tenantId);
        self::assertSame(3998, $row->totalAmount);
        self::assertSame('EUR', $row->currency);
        self::assertSame(1, $row->lineCount);
        self::assertSame('customer-request', $row->cancelReason);

        $verifyEm->getConnection()->close();
        $pool->close(Duration::seconds(1));
        unlink($dbPath);
    }
}
