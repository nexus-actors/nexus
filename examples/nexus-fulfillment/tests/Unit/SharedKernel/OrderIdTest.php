<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Tests\Unit\SharedKernel;

use InvalidArgumentException;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OrderId::class)]
final class OrderIdTest extends TestCase
{
    #[Test]
    public function generatesValidUlid(): void
    {
        $id = OrderId::generate();

        self::assertSame(26, strlen($id->value));
        self::assertTrue($id->equals(OrderId::fromString($id->value)));
    }

    #[Test]
    public function rejectsNonUlid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        OrderId::fromString('not-a-ulid');
    }

    #[Test]
    public function generatedIdsAreUnique(): void
    {
        self::assertFalse(OrderId::generate()->equals(OrderId::generate()));
    }
}
