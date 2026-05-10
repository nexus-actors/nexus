<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Attribute;

use Attribute;
use Monadial\Nexus\Ddd\Bus\Attribute\InProcess;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(InProcess::class)]
final class InProcessTest extends TestCase
{
    #[Test]
    public function targetsMethods(): void
    {
        $reflection = new ReflectionClass(InProcess::class);
        $attrs = $reflection->getAttributes(Attribute::class);

        self::assertCount(1, $attrs);

        $meta = $attrs[0]->newInstance();

        self::assertSame(Attribute::TARGET_METHOD, $meta->flags);
    }

    #[Test]
    public function constructsWithoutArgs(): void
    {
        $attr = new InProcess();

        self::assertInstanceOf(InProcess::class, $attr);
    }
}
