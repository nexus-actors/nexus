<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Backoff;

use Monadial\Duration\FiniteDuration;
use Monadial\Duration\TimeUnit\TimeUnit;
use Monadial\Nexus\Ddd\Core\Backoff\FixedDelayBackoff;
use Monadial\Nexus\Ddd\Core\Backoff\RetryPolicy;
use Monadial\Nexus\Ddd\Core\Backoff\RetryPolicyBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(RetryPolicy::class)]
#[CoversClass(RetryPolicyBuilder::class)]
final class RetryPolicyTest extends TestCase
{
    #[Test]
    public function policyDispatchesPerExceptionType(): void
    {
        $policy = RetryPolicyBuilder::create()
            ->onException(
                SomeTransient::class,
                FixedDelayBackoff::of(FiniteDuration::fromTimeUnit(50, TimeUnit::Milliseconds()), 3),
            )
            ->giveUpOn(SomeFatal::class)
            ->build();

        $delay = $policy->delayFor(1, new SomeTransient());
        self::assertFalse($delay->isNone());
        self::assertSame(50, $delay->get()->toMillis());

        $giveUp = $policy->delayFor(1, new SomeFatal());
        self::assertTrue($giveUp->isNone());
    }

    #[Test]
    public function unmappedExceptionsGiveUpByDefault(): void
    {
        $policy = RetryPolicyBuilder::create()->build();
        self::assertTrue($policy->delayFor(1, new RuntimeException())->isNone());
    }
}

final class SomeTransient extends RuntimeException {}
final class SomeFatal extends RuntimeException {}
