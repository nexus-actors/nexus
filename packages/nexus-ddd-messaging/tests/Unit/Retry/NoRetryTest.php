<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Retry;

use Exception;
use Monadial\Nexus\Ddd\Messaging\Retry\NoRetry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NoRetry::class)]
final class NoRetryTest extends TestCase
{
    #[Test]
    public function delayForAlwaysReturnsNone(): void
    {
        $strategy = new NoRetry();
        $result = $strategy->delayFor(1, new Exception('fail'));

        self::assertTrue($result->isNone());
    }

    #[Test]
    public function delayForReturnsNoneOnAttemptZero(): void
    {
        $strategy = new NoRetry();
        $result = $strategy->delayFor(0, new Exception());

        self::assertTrue($result->isNone());
    }
}
