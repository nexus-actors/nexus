<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Retry;

use Exception;
use Monadial\Nexus\Ddd\Messaging\Retry\LinearBackoff;
use Monadial\Nexus\Ddd\Messaging\Tests\Support\Durations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(LinearBackoff::class)]
final class LinearBackoffTest extends TestCase
{
    #[Test]
    public function delayForScalesLinearlyWithAttempt(): void
    {
        $strategy = new LinearBackoff(Durations::seconds(2));

        $delay1 = $strategy->delayFor(1, new Exception())->get();
        $delay2 = $strategy->delayFor(2, new Exception())->get();
        $delay3 = $strategy->delayFor(3, new Exception())->get();

        self::assertTrue($delay1->equals(Durations::seconds(2)));
        self::assertTrue($delay2->equals(Durations::seconds(4)));
        self::assertTrue($delay3->equals(Durations::seconds(6)));
    }

    #[Test]
    public function delayForReturnsSomeOnEveryAttempt(): void
    {
        $strategy = new LinearBackoff(Durations::seconds(1));
        $result = $strategy->delayFor(5, new Exception());

        self::assertTrue($result->isSome());
    }
}
