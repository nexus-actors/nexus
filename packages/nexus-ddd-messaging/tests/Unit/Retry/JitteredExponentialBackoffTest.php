<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Retry;

use Exception;
use Monadial\Nexus\Ddd\Messaging\Retry\JitteredExponentialBackoff;
use Monadial\Nexus\Ddd\Messaging\Tests\Support\Durations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(JitteredExponentialBackoff::class)]
final class JitteredExponentialBackoffTest extends TestCase
{
    #[Test]
    public function delayForReturnsSome(): void
    {
        $strategy = new JitteredExponentialBackoff(Durations::seconds(1), Durations::seconds(60));
        $result = $strategy->delayFor(1, new Exception());

        self::assertTrue($result->isSome());
    }

    #[Test]
    public function delayForIsWithinBounds(): void
    {
        $strategy = new JitteredExponentialBackoff(
            Durations::seconds(1),
            Durations::seconds(60),
        );

        for ($i = 0; $i < 20; $i++) {
            $delay = $strategy->delayFor(1, new Exception())->get();

            self::assertTrue(Durations::gte($delay, Durations::seconds(0)));
            self::assertTrue(Durations::lte($delay, Durations::seconds(60)));
        }
    }

    #[Test]
    public function delayForIsStillCappedAtMax(): void
    {
        $strategy = new JitteredExponentialBackoff(
            Durations::seconds(1),
            Durations::seconds(5),
        );

        for ($i = 0; $i < 20; $i++) {
            $delay = $strategy->delayFor(10, new Exception())->get();

            self::assertTrue(Durations::lte($delay, Durations::seconds(5)));
        }
    }
}
