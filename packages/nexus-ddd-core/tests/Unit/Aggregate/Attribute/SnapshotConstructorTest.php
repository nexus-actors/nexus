<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Aggregate\Attribute;

use Attribute;
use Monadial\Nexus\Ddd\Core\Aggregate\Attribute\SnapshotConstructor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

#[CoversClass(SnapshotConstructor::class)]
final class SnapshotConstructorTest extends TestCase
{
    #[Test]
    public function attributeTargetsStaticMethods(): void
    {
        $reflection = new ReflectionClass(SnapshotConstructor::class);
        $attrs = $reflection->getAttributes(Attribute::class);
        self::assertNotEmpty($attrs);
        $instance = $attrs[0]->newInstance();
        self::assertSame(Attribute::TARGET_METHOD, $instance->flags);
    }

    #[Test]
    public function attributeIsDiscoverableViaReflection(): void
    {
        $cls = new class {
            #[SnapshotConstructor]
            public static function rehydrate(int $a): self
            {
                return new self();
            }
        };
        $method = new ReflectionMethod($cls, 'rehydrate');
        self::assertNotEmpty($method->getAttributes(SnapshotConstructor::class));
    }
}
