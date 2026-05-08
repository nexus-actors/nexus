<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Retry;

use Exception;
use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Messaging\Retry\CustomBackoff;
use Monadial\Nexus\Ddd\Messaging\Tests\Support\Durations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;

#[CoversClass(CustomBackoff::class)]
final class CustomBackoffTest extends TestCase
{
    #[Test]
    public function delayForDelegatesToCallable(): void
    {
        $strategy = new CustomBackoff(
            static fn(int $attempt, Throwable $cause): Option => Option::some(Durations::seconds($attempt * 3)),
        );

        $delay = $strategy->delayFor(2, new Exception())->get();

        self::assertTrue($delay->equals(Durations::seconds(6)));
    }

    #[Test]
    public function delayForCanReturnNone(): void
    {
        $strategy = new CustomBackoff(
            static fn(int $attempt, Throwable $cause): Option => Option::none(),
        );

        $result = $strategy->delayFor(1, new Exception());

        self::assertTrue($result->isNone());
    }

    #[Test]
    public function callableReceivesAttemptAndCause(): void
    {
        $capturedAttempt = 0;
        $capturedException = null;

        $cause = new Exception('test-cause');

        $strategy = new CustomBackoff(
            static function (int $attempt, Throwable $c) use (&$capturedAttempt, &$capturedException): Option {
                $capturedAttempt = $attempt;
                $capturedException = $c;

                return Option::none();
            },
        );

        $strategy->delayFor(7, $cause);

        self::assertSame(7, $capturedAttempt);
        self::assertSame($cause, $capturedException);
    }
}
