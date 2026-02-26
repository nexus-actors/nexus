<?php

declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Tests\Unit;

use Monadial\Nexus\Runtime\Duration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Duration::class)]
final class DurationTest extends TestCase
{
    #[Test]
    public function createsFromSeconds(): void
    {
        $d = Duration::seconds(5);
        self::assertSame(5_000_000_000, $d->toNanos());
        self::assertSame(5_000, $d->toMillis());
        self::assertSame(5, $d->toSeconds());
    }

    #[Test]
    public function toStringFormatsHumanReadable(): void
    {
        self::assertSame('1s 500ms', (string) Duration::millis(1500));
    }
}
