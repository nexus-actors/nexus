<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\Tests\Unit\Locking;

use Closure;
use Monadial\Nexus\Persistence\Locking\LockingStrategy;
use Monadial\Nexus\Persistence\Locking\PessimisticLockProvider;
use Monadial\Nexus\Persistence\PersistenceId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(LockingStrategy::class)]
final class LockingStrategyTest extends TestCase
{
    #[Test]
    public function optimistic_is_not_pessimistic(): void
    {
        $strategy = LockingStrategy::optimistic();

        self::assertFalse($strategy->isPessimistic());
    }

    #[Test]
    public function pessimistic_is_pessimistic(): void
    {
        $provider = $this->createMock(PessimisticLockProvider::class);
        $strategy = LockingStrategy::pessimistic($provider);

        self::assertTrue($strategy->isPessimistic());
    }

    #[Test]
    public function optimistic_withLock_calls_callback_directly(): void
    {
        $strategy = LockingStrategy::optimistic();
        $id = PersistenceId::of('Test', 'test-1');

        $result = $strategy->withLock($id, static fn(): string => 'executed');

        self::assertSame('executed', $result);
    }

    #[Test]
    public function pessimistic_withLock_delegates_to_provider(): void
    {
        $id = PersistenceId::of('Test', 'test-1');
        $provider = $this->createMock(PessimisticLockProvider::class);
        $provider->expects(self::once())
            ->method('withLock')
            ->with($id, self::isInstanceOf(Closure::class))
            ->willReturnCallback(static fn(PersistenceId $id, Closure $cb): mixed => $cb());

        $strategy = LockingStrategy::pessimistic($provider);

        $result = $strategy->withLock($id, static fn(): string => 'locked');

        self::assertSame('locked', $result);
    }

    #[Test]
    public function pessimistic_withLock_propagates_return_value(): void
    {
        $id = PersistenceId::of('Test', 'test-1');
        $provider = $this->createMock(PessimisticLockProvider::class);
        $provider->method('withLock')
            ->willReturnCallback(static fn(PersistenceId $id, Closure $cb): mixed => $cb());

        $strategy = LockingStrategy::pessimistic($provider);

        $result = $strategy->withLock($id, static fn(): int => 42);

        self::assertSame(42, $result);
    }
}
