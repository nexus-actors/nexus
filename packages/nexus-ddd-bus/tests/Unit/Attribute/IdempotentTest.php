<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Attribute;

use Attribute;
use Monadial\Nexus\Ddd\Bus\Attribute\Idempotent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(Idempotent::class)]
final class IdempotentTest extends TestCase
{
    #[Test]
    public function targetsClasses(): void
    {
        $reflection = new ReflectionClass(Idempotent::class);
        $attrs = $reflection->getAttributes(Attribute::class);

        self::assertCount(1, $attrs);

        $meta = $attrs[0]->newInstance();

        self::assertSame(Attribute::TARGET_CLASS, $meta->flags);
    }

    #[Test]
    public function constructsWithDefaults(): void
    {
        $attr = new Idempotent();

        self::assertNull($attr->store);
        self::assertFalse($attr->off);
    }

    #[Test]
    public function constructsWithStoreAndOff(): void
    {
        $attr = new Idempotent(store: 'redis', off: true);

        self::assertSame('redis', $attr->store);
        self::assertTrue($attr->off);
    }
}
