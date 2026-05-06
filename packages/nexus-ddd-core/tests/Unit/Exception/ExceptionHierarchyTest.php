<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Exception;

use Monadial\Nexus\Ddd\Core\Exception\ApplyMethodAmbiguousException;
use Monadial\Nexus\Ddd\Core\Exception\ApplyMethodNotFoundException;
use Monadial\Nexus\Ddd\Core\Exception\InvalidIdentifierException;
use Monadial\Nexus\Ddd\Core\Exception\NexusDddException;
use Monadial\Nexus\Ddd\Core\Exception\NoEventsRecordedException;
use Monadial\Nexus\Ddd\Core\Exception\OptimisticLockException;
use Monadial\Nexus\Ddd\Core\Exception\ReplayFailedException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

#[CoversClass(NexusDddException::class)]
#[CoversClass(ApplyMethodNotFoundException::class)]
#[CoversClass(ApplyMethodAmbiguousException::class)]
#[CoversClass(ReplayFailedException::class)]
#[CoversClass(OptimisticLockException::class)]
#[CoversClass(InvalidIdentifierException::class)]
#[CoversClass(NoEventsRecordedException::class)]
final class ExceptionHierarchyTest extends TestCase
{
    #[Test]
    public function nexusDddExceptionIsAbstractRuntimeException(): void
    {
        $reflection = new ReflectionClass(NexusDddException::class);
        self::assertTrue($reflection->isAbstract());
        self::assertTrue($reflection->isSubclassOf(RuntimeException::class));
    }

    #[Test]
    public function allConcreteExceptionsExtendNexusDddException(): void
    {
        $concretes = [
            ApplyMethodNotFoundException::class,
            ApplyMethodAmbiguousException::class,
            ReplayFailedException::class,
            OptimisticLockException::class,
            InvalidIdentifierException::class,
            NoEventsRecordedException::class,
        ];

        foreach ($concretes as $cls) {
            self::assertTrue(
                is_subclass_of($cls, NexusDddException::class),
                "$cls must extend NexusDddException",
            );
        }
    }
}
