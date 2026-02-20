<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\Dbal\Tests\Unit;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Monadial\Nexus\Persistence\Dbal\DbalPessimisticLockProvider;
use Monadial\Nexus\Persistence\Dbal\Schema\PersistenceSchemaManager;
use Monadial\Nexus\Persistence\PersistenceId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(DbalPessimisticLockProvider::class)]
final class DbalPessimisticLockProviderTest extends TestCase
{
    private Connection $connection;
    private DbalPessimisticLockProvider $provider;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        (new PersistenceSchemaManager($this->connection))->createSchema();
        $this->provider = new DbalPessimisticLockProvider($this->connection);
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

        $count = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM nexus_persistence_lock WHERE persistence_id = ?',
            [$id->toString()],
        );
        self::assertSame(1, (int) $count);
    }

    #[Test]
    public function withLock_is_idempotent_on_lock_row(): void
    {
        $id = PersistenceId::of('Account', 'acc-1');

        // Call twice — should not throw on duplicate insert
        $this->provider->withLock($id, static fn (): null => null);
        $this->provider->withLock($id, static fn (): null => null);

        $count = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM nexus_persistence_lock WHERE persistence_id = ?',
            [$id->toString()],
        );
        self::assertSame(1, (int) $count);
    }

    #[Test]
    public function withLock_propagates_return_value(): void
    {
        $id = PersistenceId::of('Account', 'acc-1');

        $result = $this->provider->withLock($id, static fn (): int => 42);

        self::assertSame(42, $result);
    }

    #[Test]
    public function withLock_propagates_exceptions(): void
    {
        $id = PersistenceId::of('Account', 'acc-1');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('test error');

        $this->provider->withLock($id, static function (): never {
            throw new RuntimeException('test error');
        });
    }
}
