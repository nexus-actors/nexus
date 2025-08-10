<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Unit\Actor;

use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Exception\InvalidActorPathException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ActorPath::class)]
final class ActorPathTest extends TestCase
{
    #[Test]
    public function createsRoot(): void
    {
        $path = ActorPath::root();
        self::assertSame('/', (string) $path);
    }

    #[Test]
    public function parsesFromString(): void
    {
        $path = ActorPath::fromString('/user/orders');
        self::assertSame('/user/orders', (string) $path);
    }

    #[Test]
    public function createsChild(): void
    {
        $parent = ActorPath::fromString('/user');
        $child = $parent->child('orders');
        self::assertSame('/user/orders', (string) $child);
    }

    #[Test]
    public function createsNestedChild(): void
    {
        $path = ActorPath::fromString('/user')
            ->child('orders')
            ->child('order-123');
        self::assertSame('/user/orders/order-123', (string) $path);
    }

    #[Test]
    public function returnsName(): void
    {
        $path = ActorPath::fromString('/user/orders/order-123');
        self::assertSame('order-123', $path->name());
    }

    #[Test]
    public function returnsParent(): void
    {
        $path = ActorPath::fromString('/user/orders/order-123');
        $parent = $path->parent();

        self::assertTrue($parent->isSome());
        self::assertSame('/user/orders', (string) $parent->get());
    }

    #[Test]
    public function rootHasNoParent(): void
    {
        $path = ActorPath::root();
        self::assertTrue($path->parent()->isNone());
    }

    #[Test]
    public function validatesNameCharacters(): void
    {
        $path = ActorPath::fromString('/user');
        self::assertSame('/user/valid-name_123.test', (string) $path->child('valid-name_123.test'));
    }

    #[Test]
    public function rejectsEmptyPath(): void
    {
        $this->expectException(InvalidActorPathException::class);
        ActorPath::fromString('');
    }

    #[Test]
    public function rejectsPathWithoutLeadingSlash(): void
    {
        $this->expectException(InvalidActorPathException::class);
        ActorPath::fromString('user/orders');
    }

    #[Test]
    public function rejectsInvalidChildName(): void
    {
        $this->expectException(InvalidActorPathException::class);
        ActorPath::fromString('/user')->child('invalid name!');
    }

    #[Test]
    public function equalsComparesValue(): void
    {
        $a = ActorPath::fromString('/user/orders');
        $b = ActorPath::fromString('/user/orders');
        $c = ActorPath::fromString('/user/other');

        self::assertTrue($a->equals($b));
        self::assertFalse($a->equals($c));
    }

    #[Test]
    public function isChildOf(): void
    {
        $parent = ActorPath::fromString('/user');
        $child = ActorPath::fromString('/user/orders');
        $grandchild = ActorPath::fromString('/user/orders/order-123');
        $unrelated = ActorPath::fromString('/system/log');

        self::assertTrue($child->isChildOf($parent));
        self::assertFalse($grandchild->isChildOf($parent));
        self::assertFalse($unrelated->isChildOf($parent));
    }

    #[Test]
    public function isDescendantOf(): void
    {
        $parent = ActorPath::fromString('/user');
        $child = ActorPath::fromString('/user/orders');
        $grandchild = ActorPath::fromString('/user/orders/order-123');

        self::assertTrue($child->isDescendantOf($parent));
        self::assertTrue($grandchild->isDescendantOf($parent));
    }

    #[Test]
    public function depth(): void
    {
        self::assertSame(0, ActorPath::root()->depth());
        self::assertSame(1, ActorPath::fromString('/user')->depth());
        self::assertSame(2, ActorPath::fromString('/user/orders')->depth());
        self::assertSame(3, ActorPath::fromString('/user/orders/order-123')->depth());
    }
}
