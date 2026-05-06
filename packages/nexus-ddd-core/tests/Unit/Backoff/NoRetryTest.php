<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Backoff;

use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Core\Backoff\BackoffStrategy;
use Monadial\Nexus\Ddd\Core\Backoff\NoRetry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(NoRetry::class)]
final class NoRetryTest extends TestCase
{
    #[Test]
    public function alwaysReturnsNone(): void
    {
        $strategy = NoRetry::instance();
        self::assertInstanceOf(BackoffStrategy::class, $strategy);

        $result = $strategy->delayFor(1, new RuntimeException('boom'));
        self::assertInstanceOf(Option::class, $result);
        self::assertTrue($result->isNone());
    }
}
