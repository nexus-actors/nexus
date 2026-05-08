<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Exception;

use Monadial\Nexus\Ddd\Core\Exception\ApplyDuringReplayException;
use Monadial\Nexus\Ddd\Core\Exception\DomainException;
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
#[CoversClass(DomainException::class)]
#[CoversClass(ApplyDuringReplayException::class)]
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
    public function domainExceptionIsAbstractRuntimeException(): void
    {
        $reflection = new ReflectionClass(DomainException::class);
        self::assertTrue($reflection->isAbstract());
        self::assertTrue($reflection->isSubclassOf(RuntimeException::class));
    }

    #[Test]
    public function frameworkExceptionsExtendNexusDddException(): void
    {
        $framework = [
            ApplyDuringReplayException::class,
            NoEventsRecordedException::class,
            ReplayFailedException::class,
        ];

        foreach ($framework as $cls) {
            self::assertTrue(
                is_subclass_of($cls, NexusDddException::class),
                "$cls must extend NexusDddException (framework wiring fault)",
            );
            self::assertFalse(
                is_subclass_of($cls, DomainException::class),
                "$cls must NOT extend DomainException — it is framework, not domain",
            );
        }
    }

    #[Test]
    public function domainExceptionsExtendDomainException(): void
    {
        $domain = [
            InvalidIdentifierException::class,
            OptimisticLockException::class,
        ];

        foreach ($domain as $cls) {
            self::assertTrue(
                is_subclass_of($cls, DomainException::class),
                "$cls must extend DomainException (business rule violation)",
            );
            self::assertFalse(
                is_subclass_of($cls, NexusDddException::class),
                "$cls must NOT extend NexusDddException — it is domain, not framework",
            );
        }
    }

    #[Test]
    public function domainAndFrameworkRootsAreDisjoint(): void
    {
        // The whole point of the split: catching one root must not also
        // accidentally catch the other.
        self::assertFalse(is_subclass_of(DomainException::class, NexusDddException::class));
        self::assertFalse(is_subclass_of(NexusDddException::class, DomainException::class));
    }
}
