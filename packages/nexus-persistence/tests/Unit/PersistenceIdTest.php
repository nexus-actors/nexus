<?php
declare(strict_types=1);

namespace Monadial\Nexus\Persistence\Tests\Unit;

use Monadial\Nexus\Persistence\PersistenceId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PersistenceId::class)]
final class PersistenceIdTest extends TestCase
{
    #[Test]
    public function createsFromTypeAndId(): void
    {
        $id = PersistenceId::of('order', 'order-123');
        self::assertSame('order', $id->entityType);
        self::assertSame('order-123', $id->entityId);
    }

    #[Test]
    public function serializesToString(): void
    {
        $id = PersistenceId::of('order', 'order-123');
        self::assertSame('order|order-123', $id->toString());
        self::assertSame('order|order-123', (string) $id);
    }

    #[Test]
    public function parsesFromString(): void
    {
        $id = PersistenceId::fromString('order|order-123');
        self::assertSame('order', $id->entityType);
        self::assertSame('order-123', $id->entityId);
    }

    #[Test]
    public function equalityByValue(): void
    {
        $a = PersistenceId::of('order', '123');
        $b = PersistenceId::of('order', '123');
        $c = PersistenceId::of('order', '456');
        self::assertTrue($a->equals($b));
        self::assertFalse($a->equals($c));
    }

    #[Test]
    public function rejectsEmptyEntityType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PersistenceId::of('', 'id');
    }

    #[Test]
    public function rejectsEmptyEntityId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PersistenceId::of('type', '');
    }

    #[Test]
    public function rejectsPipeInEntityType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PersistenceId::of('or|der', 'id');
    }
}
