<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Backoff;

use Monadial\Duration\FiniteDuration;
use Monadial\Duration\TimeUnit\TimeUnit;
use Monadial\Nexus\Ddd\Core\Backoff\FixedDelayBackoff;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(FixedDelayBackoff::class)]
final class FixedDelayBackoffTest extends TestCase
{
    #[Test]
    public function delaysFixedAmountUntilMaxAttempts(): void
    {
        $strategy = FixedDelayBackoff::of(
            FiniteDuration::fromTimeUnit(1, TimeUnit::Seconds()),
            maxAttempts: 3,
        );

        self::assertSame(1_000, $strategy->delayFor(1, new RuntimeException())->get()->toMillis());
        self::assertSame(1_000, $strategy->delayFor(2, new RuntimeException())->get()->toMillis());
        self::assertTrue($strategy->delayFor(4, new RuntimeException())->isNone());
    }
}
