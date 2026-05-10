<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Attribute;

use Attribute;
use Monadial\Nexus\Ddd\Bus\Attribute\Sensitive;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(Sensitive::class)]
final class SensitiveTest extends TestCase
{
    #[Test]
    public function targetsProperties(): void
    {
        $reflection = new ReflectionClass(Sensitive::class);
        $attrs = $reflection->getAttributes(Attribute::class);

        self::assertCount(1, $attrs);

        $meta = $attrs[0]->newInstance();

        self::assertSame(Attribute::TARGET_PROPERTY, $meta->flags);
    }

    #[Test]
    public function constructsWithoutArgs(): void
    {
        $attr = new Sensitive();

        self::assertInstanceOf(Sensitive::class, $attr);
    }
}
