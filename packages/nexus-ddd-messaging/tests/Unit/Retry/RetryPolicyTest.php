<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Retry;

use InvalidArgumentException;
use LogicException;
use Monadial\Nexus\Ddd\Messaging\Retry\FixedDelayBackoff;
use Monadial\Nexus\Ddd\Messaging\Retry\RetryPolicy;
use Monadial\Nexus\Ddd\Messaging\Tests\Support\Durations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

#[CoversClass(RetryPolicy::class)]
final class RetryPolicyTest extends TestCase
{
    #[Test]
    public function firstMatchingMappingWins(): void
    {
        $policy = new RetryPolicy(
            handlers: [
                RuntimeException::class => new FixedDelayBackoff(Durations::seconds(1)),
                Throwable::class => new FixedDelayBackoff(Durations::seconds(99)),
            ],
            giveUpSet: [],
        );

        $result = $policy->delayFor(1, new RuntimeException())->get();

        self::assertTrue($result->equals(Durations::seconds(1)));
    }

    #[Test]
    public function giveUpSetTakesPrecedenceOverHandlers(): void
    {
        $policy = new RetryPolicy(
            handlers: [
                Throwable::class => new FixedDelayBackoff(Durations::seconds(1)),
            ],
            giveUpSet: [InvalidArgumentException::class => true],
        );

        self::assertTrue($policy->delayFor(1, new InvalidArgumentException())->isNone());
    }

    #[Test]
    public function unmatchedExceptionReturnsNone(): void
    {
        $policy = new RetryPolicy(handlers: [], giveUpSet: []);

        self::assertTrue($policy->delayFor(1, new LogicException())->isNone());
    }
}
