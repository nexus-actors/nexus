<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Tests\Unit\Event;

use Monadial\Nexus\Ddd\Aggregate\Event\AggregateStreamId;
use Monadial\Nexus\Ddd\Core\Tests\Support\TestUlidId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

#[CoversClass(AggregateStreamId::class)]
final class AggregateStreamIdTest extends TestCase
{
    #[Test]
    public function constructorAssignsAggregateClassAndId(): void
    {
        $id = new AggregateStreamId('App\\Order', 'order-1');

        self::assertSame('App\\Order', $id->aggregateClass);
        self::assertSame('order-1', $id->aggregateId);
    }

    #[Test]
    public function forFactoryDerivesIdFromIdentifierValue(): void
    {
        $identifier = new TestUlidId((new Ulid())->toBase32());
        $id = AggregateStreamId::for(self::class, $identifier);

        self::assertSame(self::class, $id->aggregateClass);
        self::assertSame($identifier->value(), $id->aggregateId);
    }

    #[Test]
    public function toStringJoinsClassAndIdWithSlash(): void
    {
        $id = new AggregateStreamId('App\\Order', 'order-1');

        self::assertSame('App\\Order/order-1', $id->toString());
        self::assertSame('App\\Order/order-1', (string) $id);
    }

    #[Test]
    public function equalsReturnsTrueForSamePair(): void
    {
        $a = new AggregateStreamId('App\\Order', 'order-1');
        $b = new AggregateStreamId('App\\Order', 'order-1');

        self::assertTrue($a->equals($b));
    }

    #[Test]
    public function equalsReturnsFalseWhenClassDiffers(): void
    {
        $a = new AggregateStreamId('App\\Order', 'order-1');
        $b = new AggregateStreamId('App\\Customer', 'order-1');

        self::assertFalse($a->equals($b));
    }

    #[Test]
    public function equalsReturnsFalseWhenIdDiffers(): void
    {
        $a = new AggregateStreamId('App\\Order', 'order-1');
        $b = new AggregateStreamId('App\\Order', 'order-2');

        self::assertFalse($a->equals($b));
    }
}
