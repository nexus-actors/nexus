<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Unit\Lifecycle;

use Monadial\Nexus\Core\Lifecycle\ReceiveTimeout;
use Monadial\Nexus\Core\Lifecycle\Signal;
use Monadial\Nexus\Runtime\Duration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReceiveTimeout::class)]
final class ReceiveTimeoutTest extends TestCase
{
    #[Test]
    public function implementsSignal(): void
    {
        $signal = new ReceiveTimeout(Duration::seconds(30));

        self::assertInstanceOf(Signal::class, $signal);
    }

    #[Test]
    public function carriesConfiguredDuration(): void
    {
        $signal = new ReceiveTimeout(Duration::seconds(30));

        self::assertTrue($signal->configured->equals(Duration::seconds(30)));
    }

    #[Test]
    public function differentDurationsAreDistinct(): void
    {
        $a = new ReceiveTimeout(Duration::millis(100));
        $b = new ReceiveTimeout(Duration::seconds(5));

        self::assertFalse($a->configured->equals($b->configured));
    }
}
