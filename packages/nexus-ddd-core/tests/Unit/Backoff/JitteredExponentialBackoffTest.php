<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Backoff;

use Monadial\Duration\FiniteDuration;
use Monadial\Duration\TimeUnit\TimeUnit;
use Monadial\Nexus\Ddd\Core\Backoff\JitteredExponentialBackoff;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(JitteredExponentialBackoff::class)]
final class JitteredExponentialBackoffTest extends TestCase
{
    #[Test]
    public function jitteredDelayIsWithinExpectedRange(): void
    {
        $strategy = JitteredExponentialBackoff::of(
            FiniteDuration::fromTimeUnit(100, TimeUnit::Milliseconds()),
            cap: FiniteDuration::fromTimeUnit(10_000, TimeUnit::Milliseconds()),
            maxAttempts: 5,
        );

        for ($i = 0; $i < 50; $i++) {
            $delay = $strategy->delayFor(1, new RuntimeException())->get();
            self::assertGreaterThanOrEqual(100, $delay->toMillis());
            self::assertLessThanOrEqual(200, $delay->toMillis());
        }
    }

    #[Test]
    public function noneAfterMaxAttempts(): void
    {
        $strategy = JitteredExponentialBackoff::of(
            FiniteDuration::fromTimeUnit(10, TimeUnit::Milliseconds()),
            FiniteDuration::fromTimeUnit(100, TimeUnit::Milliseconds()),
            2,
        );
        self::assertTrue($strategy->delayFor(3, new RuntimeException())->isNone());
    }
}
