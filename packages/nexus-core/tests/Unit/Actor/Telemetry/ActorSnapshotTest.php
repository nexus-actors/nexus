<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Unit\Actor\Telemetry;

use Monadial\Nexus\Core\Actor\Telemetry\ActorSnapshot;
use Monadial\Nexus\Core\Actor\Telemetry\ActorSystemSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ActorSnapshot::class)]
#[CoversClass(ActorSystemSnapshot::class)]
final class ActorSnapshotTest extends TestCase
{
    #[Test]
    public function actor_snapshot_exposes_fields(): void
    {
        $child = new ActorSnapshot('/user/orders/p', true, 0, PHP_INT_MAX, false, []);
        $snap  = new ActorSnapshot('/user/orders', true, 3, 1000, true, [$child]);

        self::assertSame('/user/orders', $snap->path);
        self::assertTrue($snap->alive);
        self::assertSame(3, $snap->mailboxDepth);
        self::assertSame(1000, $snap->mailboxCapacity);
        self::assertTrue($snap->mailboxBounded);
        self::assertCount(1, $snap->children);
    }

    #[Test]
    public function actor_snapshot_round_trips_through_array(): void
    {
        $child = new ActorSnapshot('/user/orders/p', true, 0, PHP_INT_MAX, false, []);
        $snap  = new ActorSnapshot('/user/orders', true, 3, 1000, true, [$child]);

        $restored = ActorSnapshot::fromArray($snap->toArray());

        self::assertSame($snap->path, $restored->path);
        self::assertSame($snap->mailboxDepth, $restored->mailboxDepth);
        self::assertCount(1, $restored->children);
        self::assertSame('/user/orders/p', $restored->children[0]->path);
    }

    #[Test]
    public function actor_system_snapshot_round_trips_through_array(): void
    {
        $actor = new ActorSnapshot('/user/orders', true, 0, PHP_INT_MAX, false, []);
        $snap  = new ActorSystemSnapshot('my-system', '01HXYZ', true, [$actor], 2);

        $restored = ActorSystemSnapshot::fromArray($snap->toArray());

        self::assertSame('my-system', $restored->systemName);
        self::assertSame('01HXYZ', $restored->writerId);
        self::assertTrue($restored->isRunning);
        self::assertSame(2, $restored->deadLettersCount);
        self::assertCount(1, $restored->actors);
    }
}
