<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\Tests\Unit\Exception;

use Monadial\Nexus\Persistence\Exception\WriterConflictException;
use Monadial\Nexus\Persistence\PersistenceId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Uid\Ulid;

#[CoversClass(WriterConflictException::class)]
final class WriterConflictExceptionTest extends TestCase
{
    #[Test]
    public function exposes_all_properties(): void
    {
        $id = PersistenceId::of('Order', 'order-1');
        $writerA = new Ulid();
        $writerB = new Ulid();
        $exception = new WriterConflictException($id, $writerA, $writerB, 5);

        self::assertSame($id, $exception->persistenceId);
        self::assertSame($writerA, $exception->expectedWriter);
        self::assertSame($writerB, $exception->actualWriter);
        self::assertSame(5, $exception->sequenceNr);
    }

    #[Test]
    public function extends_runtime_exception(): void
    {
        $exception = new WriterConflictException(
            PersistenceId::of('Order', 'order-1'),
            new Ulid(),
            new Ulid(),
            1,
        );

        self::assertInstanceOf(RuntimeException::class, $exception);
    }

    #[Test]
    public function message_describes_conflict(): void
    {
        $writerA = new Ulid();
        $writerB = new Ulid();

        $exception = new WriterConflictException(
            PersistenceId::of('Order', 'order-1'),
            $writerA,
            $writerB,
            5,
        );

        self::assertStringContainsString((string) $writerA, $exception->getMessage());
        self::assertStringContainsString((string) $writerB, $exception->getMessage());
        self::assertStringContainsString('Order|order-1', $exception->getMessage());
    }
}
