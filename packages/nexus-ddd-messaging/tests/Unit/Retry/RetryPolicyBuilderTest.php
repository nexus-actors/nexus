<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Retry;

use InvalidArgumentException;
use LogicException;
use Monadial\Nexus\Ddd\Messaging\Retry\FixedDelayBackoff;
use Monadial\Nexus\Ddd\Messaging\Retry\RetryPolicyBuilder;
use Monadial\Nexus\Ddd\Messaging\Tests\Support\Durations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(RetryPolicyBuilder::class)]
final class RetryPolicyBuilderTest extends TestCase
{
    #[Test]
    public function buildProducesRetryPolicyThatMatchesOnException(): void
    {
        $policy = RetryPolicyBuilder::create()
            ->onException(RuntimeException::class, new FixedDelayBackoff(Durations::seconds(2)))
            ->build();

        $result = $policy->delayFor(1, new RuntimeException());

        self::assertTrue($result->isSome());
        self::assertTrue($result->get()->equals(Durations::seconds(2)));
    }

    #[Test]
    public function giveUpOnProducesNoneForMatchedClass(): void
    {
        $policy = RetryPolicyBuilder::create()
            ->onException(RuntimeException::class, new FixedDelayBackoff(Durations::seconds(1)))
            ->giveUpOn(InvalidArgumentException::class)
            ->build();

        self::assertTrue($policy->delayFor(1, new InvalidArgumentException())->isNone());
    }

    #[Test]
    public function unmatchedExceptionReturnsNone(): void
    {
        $policy = RetryPolicyBuilder::create()->build();

        self::assertTrue($policy->delayFor(1, new LogicException())->isNone());
    }
}
