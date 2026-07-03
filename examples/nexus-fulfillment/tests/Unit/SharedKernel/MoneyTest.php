<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Tests\Unit\SharedKernel;

use InvalidArgumentException;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Money;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Money::class)]
final class MoneyTest extends TestCase
{
    #[Test]
    public function holdsMinorUnitsAndCurrency(): void
    {
        $price = Money::of(1999, 'EUR');

        self::assertSame(1999, $price->amount);
        self::assertSame('EUR', $price->currency);
    }

    #[Test]
    public function rejectsMalformedCurrency(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of(100, 'eur');
    }

    #[Test]
    public function addsSameCurrency(): void
    {
        $sum = Money::of(1000, 'EUR')->add(Money::of(500, 'EUR'));

        self::assertTrue($sum->equals(Money::of(1500, 'EUR')));
    }

    #[Test]
    public function refusesToAddDifferentCurrencies(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of(1000, 'EUR')->add(Money::of(500, 'USD'));
    }

    #[Test]
    public function multipliesByNonNegativeFactor(): void
    {
        self::assertTrue(Money::of(250, 'EUR')->multiplyBy(4)->equals(Money::of(1000, 'EUR')));
    }

    #[Test]
    public function refusesNegativeFactor(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of(250, 'EUR')->multiplyBy(-1);
    }

    #[Test]
    public function equalityRequiresAmountAndCurrency(): void
    {
        self::assertTrue(Money::of(100, 'EUR')->equals(Money::of(100, 'EUR')));
        self::assertFalse(Money::of(100, 'EUR')->equals(Money::of(100, 'USD')));
        self::assertFalse(Money::of(100, 'EUR')->equals(Money::of(101, 'EUR')));
    }
}
