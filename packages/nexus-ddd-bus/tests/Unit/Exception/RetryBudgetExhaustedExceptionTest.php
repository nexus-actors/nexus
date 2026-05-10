<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Exception;

use Monadial\Nexus\Ddd\Bus\Exception\BusRuntimeException;
use Monadial\Nexus\Ddd\Bus\Exception\RetryableFailure;
use Monadial\Nexus\Ddd\Bus\Exception\RetryBudgetExhaustedException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(RetryBudgetExhaustedException::class)]
final class RetryBudgetExhaustedExceptionTest extends TestCase
{
    #[Test]
    public function forBuildsExceptionWithAttemptsBudgetAndCause(): void
    {
        $cause = new RuntimeException('upstream conflict');
        $ex = RetryBudgetExhaustedException::for(5, 250, $cause);

        self::assertInstanceOf(BusRuntimeException::class, $ex);
        self::assertInstanceOf(RetryableFailure::class, $ex);
        self::assertStringContainsString('5', $ex->getMessage());
        self::assertStringContainsString('250', $ex->getMessage());
        self::assertSame($cause, $ex->getPrevious());
    }
}
