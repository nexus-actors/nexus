<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Backoff;

use Monadial\Duration\FiniteDuration;
use Monadial\Duration\TimeUnit\TimeUnit;
use Monadial\Nexus\Ddd\Core\Backoff\LinearBackoff;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(LinearBackoff::class)]
final class LinearBackoffTest extends TestCase
{
    #[Test]
    public function delayScalesLinearlyByAttempt(): void
    {
        $strategy = LinearBackoff::of(
            FiniteDuration::fromTimeUnit(100, TimeUnit::Milliseconds()),
            maxAttempts: 3,
        );

        self::assertSame(100, $strategy->delayFor(1, new RuntimeException())->get()->toMillis());
        self::assertSame(200, $strategy->delayFor(2, new RuntimeException())->get()->toMillis());
        self::assertSame(300, $strategy->delayFor(3, new RuntimeException())->get()->toMillis());
        self::assertTrue($strategy->delayFor(4, new RuntimeException())->isNone());
    }
}
