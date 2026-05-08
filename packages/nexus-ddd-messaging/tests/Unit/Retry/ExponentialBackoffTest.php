<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Retry;

use Exception;
use Monadial\Nexus\Ddd\Messaging\Retry\ExponentialBackoff;
use Monadial\Nexus\Ddd\Messaging\Tests\Support\Durations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExponentialBackoff::class)]
final class ExponentialBackoffTest extends TestCase
{
    #[Test]
    public function delayForGrowsExponentially(): void
    {
        $strategy = new ExponentialBackoff(Durations::seconds(1), Durations::seconds(60));

        $delay1 = $strategy->delayFor(1, new Exception())->get();
        $delay2 = $strategy->delayFor(2, new Exception())->get();
        $delay3 = $strategy->delayFor(3, new Exception())->get();

        self::assertTrue($delay1->equals(Durations::seconds(2)));
        self::assertTrue($delay2->equals(Durations::seconds(4)));
        self::assertTrue($delay3->equals(Durations::seconds(8)));
    }

    #[Test]
    public function delayForIsCappedAtMax(): void
    {
        $strategy = new ExponentialBackoff(Durations::seconds(1), Durations::seconds(10));

        $delay = $strategy->delayFor(10, new Exception())->get();

        self::assertTrue(Durations::lte($delay, Durations::seconds(10)));
    }

    #[Test]
    public function delayForReturnsSomeAlways(): void
    {
        $strategy = new ExponentialBackoff(Durations::seconds(1), Durations::seconds(30));
        $result = $strategy->delayFor(5, new Exception());

        self::assertTrue($result->isSome());
    }
}
