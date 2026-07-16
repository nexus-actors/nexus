<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Tests\Unit\Pool;

use Doctrine\ORM\EntityManagerInterface;
use Monadial\Nexus\Doctrine\Orm\Pool\PooledEntityManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PooledEntityManager::class)]
final class PooledEntityManagerTest extends TestCase
{
    #[Test]
    public function delegatesIsOpenToInner(): void
    {
        $inner = $this->createStub(EntityManagerInterface::class);
        $inner->method('isOpen')->willReturn(false);

        $pem = new PooledEntityManager($inner);
        self::assertFalse($pem->isOpen());
    }

    #[Test]
    public function exposesBorrowCount(): void
    {
        $inner = $this->createStub(EntityManagerInterface::class);
        $pem = new PooledEntityManager($inner);

        self::assertSame(0, $pem->borrowCount());
        $pem->markBorrowed();
        self::assertSame(1, $pem->borrowCount());
        $pem->markBorrowed();
        self::assertSame(2, $pem->borrowCount());
    }

    #[Test]
    public function clearDelegatesToInner(): void
    {
        $inner = $this->createMock(EntityManagerInterface::class);
        $inner->expects(self::once())->method('clear');

        (new PooledEntityManager($inner))->clear();
    }
}
