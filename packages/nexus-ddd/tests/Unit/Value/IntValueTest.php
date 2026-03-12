<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Tests\Unit\Value;

use Monadial\Nexus\Ddd\Value\IntValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(IntValue::class)]
final class IntValueTest extends TestCase
{
    private IntValue $subject;

    #[Test]
    public function toStringReturnsWrappedValue(): void
    {
        self::assertSame('42', (string) $this->subject);
    }

    #[Test]
    public function equalsReturnsTrueForSameValue(): void
    {
        $other = new readonly class (42) extends IntValue {};

        self::assertTrue($this->subject->equals($other));
    }

    #[Test]
    public function equalsReturnsFalseForDifferentValue(): void
    {
        $other = new readonly class (99) extends IntValue {};

        self::assertFalse($this->subject->equals($other));
    }

    protected function setUp(): void
    {
        $this->subject = new readonly class (42) extends IntValue {};
    }
}
