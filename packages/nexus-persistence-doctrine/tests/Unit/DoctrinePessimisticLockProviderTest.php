<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\Doctrine\Tests\Unit;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Monadial\Nexus\Persistence\Dbal\Schema\PersistenceSchemaManager;
use Monadial\Nexus\Persistence\Doctrine\DoctrinePessimisticLockProvider;
use Monadial\Nexus\Persistence\PersistenceId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DoctrinePessimisticLockProvider::class)]
final class DoctrinePessimisticLockProviderTest extends TestCase
{
    private EntityManagerInterface $em;
    private DoctrinePessimisticLockProvider $provider;

    protected function setUp(): void
    {
        $config = ORMSetup::createAttributeMetadataConfiguration(
            [__DIR__ . '/../../src/Entity'],
            isDevMode: true,
        );
        $config->enableNativeLazyObjects(true);

        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ], $config);

        $this->em = new EntityManager($connection, $config);

        // Create the lock table via schema manager
        (new PersistenceSchemaManager($connection))->createSchema();

        $this->provider = new DoctrinePessimisticLockProvider($this->em);
    }

    #[Test]
    public function withLock_executes_callback_and_returns_result(): void
    {
        $id = PersistenceId::of('Account', 'acc-1');

        $result = $this->provider->withLock($id, static fn (): string => 'executed');

        self::assertSame('executed', $result);
    }

    #[Test]
    public function withLock_creates_lock_row(): void
    {
        $id = PersistenceId::of('Account', 'acc-1');

        $this->provider->withLock($id, static fn (): null => null);

        $count = $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM nexus_persistence_lock WHERE persistence_id = ?',
            [$id->toString()],
        );
        self::assertSame(1, (int) $count);
    }

    #[Test]
    public function withLock_is_idempotent_on_lock_row(): void
    {
        $id = PersistenceId::of('Account', 'acc-1');

        $this->provider->withLock($id, static fn (): null => null);
        $this->provider->withLock($id, static fn (): null => null);

        $count = $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM nexus_persistence_lock WHERE persistence_id = ?',
            [$id->toString()],
        );
        self::assertSame(1, (int) $count);
    }
}
