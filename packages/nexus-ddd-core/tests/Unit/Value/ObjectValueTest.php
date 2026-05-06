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
}

final readonly class FullName extends ObjectValue
{
    public function __construct(
        public string $first,
        public string $last,
    ) {}
}

final readonly class OtherCompositeValue extends ObjectValue
{
    public function __construct(
        public string $first,
        public string $last,
    ) {}
}
