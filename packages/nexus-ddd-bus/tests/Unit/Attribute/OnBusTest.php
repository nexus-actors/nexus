<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Attribute;

use Attribute;
use Monadial\Nexus\Ddd\Bus\Attribute\OnBus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(OnBus::class)]
final class OnBusTest extends TestCase
{
    #[Test]
    public function targetsClasses(): void
    {
        $reflection = new ReflectionClass(OnBus::class);
        $attrs = $reflection->getAttributes(Attribute::class);

        self::assertCount(1, $attrs);

        $meta = $attrs[0]->newInstance();

        self::assertSame(Attribute::TARGET_CLASS, $meta->flags);
    }

    #[Test]
    public function constructsWithBusName(): void
    {
        $attr = new OnBus(name: 'orders');

        self::assertSame('orders', $attr->name);
    }
}
