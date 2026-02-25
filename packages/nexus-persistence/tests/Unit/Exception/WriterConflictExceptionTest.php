<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\Tests\Unit\Exception;

use Monadial\Nexus\Persistence\Exception\WriterConflictException;
use Monadial\Nexus\Persistence\PersistenceId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(WriterConflictException::class)]
final class WriterConflictExceptionTest extends TestCase
{
    #[Test]
    public function exposes_all_properties(): void
    {
        $id = PersistenceId::of('Order', 'order-1');
        $exception = new WriterConflictException($id, 'writer-a', 'writer-b', 5);

        self::assertSame($id, $exception->persistenceId);
        self::assertSame('writer-a', $exception->expectedWriter);
        self::assertSame('writer-b', $exception->actualWriter);
        self::assertSame(5, $exception->sequenceNr);
    }

    #[Test]
    public function extends_runtime_exception(): void
    {
        $exception = new WriterConflictException(
            PersistenceId::of('Order', 'order-1'),
            'writer-a',
            'writer-b',
            1,
        );

        self::assertInstanceOf(RuntimeException::class, $exception);
    }

    #[Test]
    public function message_describes_conflict(): void
    {
        $exception = new WriterConflictException(
            PersistenceId::of('Order', 'order-1'),
            'writer-a',
            'writer-b',
            5,
        );

        self::assertStringContainsString('writer-a', $exception->getMessage());
        self::assertStringContainsString('writer-b', $exception->getMessage());
        self::assertStringContainsString('Order|order-1', $exception->getMessage());
    }
}
