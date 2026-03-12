<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Tests\Unit\Value;

use DomainException;
use Monadial\Nexus\Ddd\Value\StringValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(StringValue::class)]
final class StringValueTest extends TestCase
{
    private StringValue $subject;

    #[Test]
    public function toStringReturnsWrappedValue(): void
    {
        self::assertSame('hello', (string) $this->subject);
    }

    #[Test]
    public function equalsReturnsTrueForSameValue(): void
    {
        $other = new readonly class ('hello') extends StringValue {};

        self::assertTrue($this->subject->equals($other));
    }

    #[Test]
    public function equalsReturnsFalseForDifferentValue(): void
    {
        $other = new readonly class ('world') extends StringValue {};

        self::assertFalse($this->subject->equals($other));
    }

    #[Test]
    public function subclassValidatesInConstructor(): void
    {
        $this->expectException(DomainException::class);

        new readonly class ('') extends StringValue {
            public function __construct(string $value)
            {
                if ($value === '') {
                    throw new DomainException('Value cannot be empty');
                }

                parent::__construct($value);
            }
        };
    }

    protected function setUp(): void
    {
        $this->subject = new readonly class ('hello') extends StringValue {};
    }
}
