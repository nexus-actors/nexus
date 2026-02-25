<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Unit\Exception;

use Monadial\Nexus\Core\Exception\NexusException;
use Monadial\Nexus\Core\Exception\StashOverflowException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(StashOverflowException::class)]
final class StashOverflowExceptionTest extends TestCase
{
    #[Test]
    public function extends_nexus_exception(): void
    {
        $exception = new StashOverflowException(10, 10);

        self::assertInstanceOf(NexusException::class, $exception);
    }

    #[Test]
    public function message_includes_capacity(): void
    {
        $exception = new StashOverflowException(100, 100);

        self::assertStringContainsString('100', $exception->getMessage());
    }

    #[Test]
    public function exposes_capacity_and_size(): void
    {
        $exception = new StashOverflowException(50, 50);

        self::assertSame(50, $exception->capacity);
        self::assertSame(50, $exception->size);
    }
}
