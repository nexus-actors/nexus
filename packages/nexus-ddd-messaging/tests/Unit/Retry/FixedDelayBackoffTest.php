<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Retry;

use Exception;
use Monadial\Nexus\Ddd\Messaging\Retry\FixedDelayBackoff;
use Monadial\Nexus\Ddd\Messaging\Tests\Support\Durations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(FixedDelayBackoff::class)]
final class FixedDelayBackoffTest extends TestCase
{
    #[Test]
    public function delayForReturnsSomeWithFixedDelay(): void
    {
        $strategy = new FixedDelayBackoff(Durations::seconds(2));
        $result = $strategy->delayFor(1, new Exception());

        self::assertTrue($result->isSome());

        $delay = $result->get();
        self::assertTrue($delay->equals(Durations::seconds(2)));
    }

    #[Test]
    public function delayIsTheSameForEveryAttempt(): void
    {
        $strategy = new FixedDelayBackoff(Durations::seconds(5));

        $delay1 = $strategy->delayFor(1, new Exception())->get();
        $delay2 = $strategy->delayFor(2, new Exception())->get();
        $delay3 = $strategy->delayFor(10, new Exception())->get();

        self::assertTrue($delay1->equals(Durations::seconds(5)));
        self::assertTrue($delay2->equals(Durations::seconds(5)));
        self::assertTrue($delay3->equals(Durations::seconds(5)));
    }
}
