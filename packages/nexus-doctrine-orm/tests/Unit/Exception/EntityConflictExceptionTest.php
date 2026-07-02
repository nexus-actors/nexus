<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Tests\Unit\Exception;

use Doctrine\ORM\OptimisticLockException;
use Monadial\Nexus\Core\Exception\NexusException;
use Monadial\Nexus\Doctrine\Orm\Exception\EntityConflictException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(EntityConflictException::class)]
final class EntityConflictExceptionTest extends TestCase
{
    #[Test]
    public function wrapsDoctrineOptimisticLock(): void
    {
        $inner = OptimisticLockException::lockFailed(new stdClass());
        $e = new EntityConflictException(stdClass::class, 'k', $inner);

        self::assertInstanceOf(NexusException::class, $e);
        self::assertSame($inner, $e->getPrevious());
        self::assertStringContainsString('stdClass', $e->getMessage());
        self::assertStringContainsString('k', $e->getMessage());
        self::assertSame(stdClass::class, $e->entityClass);
        self::assertSame('k', $e->id);
    }
}
