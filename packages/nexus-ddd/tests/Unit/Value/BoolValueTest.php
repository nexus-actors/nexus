<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Tests\Unit\Value;

use Monadial\Nexus\Ddd\Value\BoolValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(BoolValue::class)]
final class BoolValueTest extends TestCase
{
    #[Test]
    public function toStringReturnsTrueString(): void
    {
        $value = new readonly class (true) extends BoolValue {};

        self::assertSame('true', (string) $value);
    }

    #[Test]
    public function toStringReturnsFalseString(): void
    {
        $value = new readonly class (false) extends BoolValue {};

        self::assertSame('false', (string) $value);
    }

    #[Test]
    public function equalsReturnsTrueForSameValue(): void
    {
        $a = new readonly class (true) extends BoolValue {};
        $b = new readonly class (true) extends BoolValue {};

        self::assertTrue($a->equals($b));
    }
}
