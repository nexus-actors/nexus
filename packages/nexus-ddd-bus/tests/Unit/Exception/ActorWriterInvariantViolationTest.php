<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Exception;

use Monadial\Nexus\Ddd\Bus\Exception\ActorWriterInvariantViolation;
use Monadial\Nexus\Ddd\Bus\Exception\BusRuntimeException;
use Monadial\Nexus\Ddd\Messaging\Exception\TerminalFailure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ActorWriterInvariantViolation::class)]
final class ActorWriterInvariantViolationTest extends TestCase
{
    #[Test]
    public function forOptimisticLockBuildsExceptionWithAggregateAndVersions(): void
    {
        $ex = ActorWriterInvariantViolation::forOptimisticLock('App\\Order\\Order', 'order-42', 5, 6);

        self::assertInstanceOf(BusRuntimeException::class, $ex);
        self::assertInstanceOf(TerminalFailure::class, $ex);
        self::assertStringContainsString('App\\Order\\Order', $ex->getMessage());
        self::assertStringContainsString('order-42', $ex->getMessage());
        self::assertStringContainsString('5', $ex->getMessage());
        self::assertStringContainsString('6', $ex->getMessage());
    }
}
