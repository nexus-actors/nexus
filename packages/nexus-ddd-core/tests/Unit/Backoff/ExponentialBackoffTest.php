<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Backoff;

use Monadial\Duration\FiniteDuration;
use Monadial\Duration\TimeUnit\TimeUnit;
use Monadial\Nexus\Ddd\Core\Backoff\ExponentialBackoff;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(ExponentialBackoff::class)]
final class ExponentialBackoffTest extends TestCase
{
    #[Test]
    public function delayDoublesPerAttemptUntilCap(): void
    {
        $strategy = ExponentialBackoff::of(
            FiniteDuration::fromTimeUnit(100, TimeUnit::Milliseconds()),
            cap: FiniteDuration::fromTimeUnit(500, TimeUnit::Milliseconds()),
            maxAttempts: 5,
        );

        self::assertSame(100, $strategy->delayFor(1, new RuntimeException())->get()->toMillis());
        self::assertSame(200, $strategy->delayFor(2, new RuntimeException())->get()->toMillis());
        self::assertSame(400, $strategy->delayFor(3, new RuntimeException())->get()->toMillis());
        self::assertSame(500, $strategy->delayFor(4, new RuntimeException())->get()->toMillis());
        self::assertSame(500, $strategy->delayFor(5, new RuntimeException())->get()->toMillis());
        self::assertTrue($strategy->delayFor(6, new RuntimeException())->isNone());
    }
}
