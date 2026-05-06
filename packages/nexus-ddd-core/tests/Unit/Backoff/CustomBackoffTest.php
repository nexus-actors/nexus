<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Backoff;

use Fp\Functional\Option\Option;
use Monadial\Duration\FiniteDuration;
use Monadial\Duration\TimeUnit\TimeUnit;
use Monadial\Nexus\Ddd\Core\Backoff\CustomBackoff;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(CustomBackoff::class)]
final class CustomBackoffTest extends TestCase
{
    #[Test]
    public function delegatesToProvidedCallable(): void
    {
        $strategy = CustomBackoff::of(
            static fn(int $attempt, \Throwable $cause): Option => $attempt < 3
                ? Option::some(FiniteDuration::fromTimeUnit(50 * $attempt, TimeUnit::Milliseconds()))
                : Option::none(),
        );

        self::assertSame(50, $strategy->delayFor(1, new RuntimeException())->get()->toMillis());
        self::assertSame(100, $strategy->delayFor(2, new RuntimeException())->get()->toMillis());
        self::assertTrue($strategy->delayFor(3, new RuntimeException())->isNone());
    }
}
