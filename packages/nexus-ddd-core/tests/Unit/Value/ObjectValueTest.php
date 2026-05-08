<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Value;

use Monadial\Nexus\Ddd\Core\Value\ObjectValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ObjectValue::class)]
final class ObjectValueTest extends TestCase
{
    #[Test]
    public function structuralEqualityByDeclaredProperties(): void
    {
        $a = new FullName('Ada', 'Lovelace');
        $b = new FullName('Ada', 'Lovelace');
        $c = new FullName('Charles', 'Babbage');

        self::assertTrue($a->equals($b));
        self::assertFalse($a->equals($c));
    }

    #[Test]
    public function differentTypesAreNotEqual(): void
    {
        $a = new FullName('Ada', 'Lovelace');
        $b = new OtherCompositeValue('Ada', 'Lovelace');
        self::assertFalse($a->equals($b));
    }

    #[Test]
    public function withProducesNewInstanceWithChangedField(): void
    {
        $original = new FullName('Ada', 'Lovelace');
        $renamed = $original->with(['last' => 'King']);

        self::assertSame('Ada', $renamed->first);    // unchanged
        self::assertSame('King', $renamed->last);    // changed
        self::assertSame('Lovelace', $original->last);   // original untouched
        self::assertNotSame($original, $renamed);
    }

    #[Test]
    public function withMultipleFieldsChangedAtOnce(): void
    {
        $a = new FullName('Ada', 'Lovelace');
        $b = $a->with(['first' => 'Charles', 'last' => 'Babbage']);

        self::assertSame('Charles', $b->first);
        self::assertSame('Babbage', $b->last);
    }

    #[Test]
    public function withEmptyChangesReturnsEqualInstance(): void
    {
        $a = new FullName('Ada', 'Lovelace');
        $b = $a->with([]);

        self::assertTrue($a->equals($b));
        self::assertNotSame($a, $b);
    }
}

final readonly class FullName extends ObjectValue
{
    public function __construct(public string $first, public string $last,) {}
}

final readonly class OtherCompositeValue extends ObjectValue
{
    public function __construct(public string $first, public string $last,) {}
}
