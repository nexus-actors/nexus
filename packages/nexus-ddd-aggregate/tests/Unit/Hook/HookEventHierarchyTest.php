<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Tests\Unit\Hook;

use DateTimeImmutable;
use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Aggregate\Event\AggregateStreamId;
use Monadial\Nexus\Ddd\Aggregate\Event\Hook\AfterEventStoreAppend;
use Monadial\Nexus\Ddd\Aggregate\Event\Hook\AfterEventStoreDelete;
use Monadial\Nexus\Ddd\Aggregate\Event\Hook\AfterEventStoreLoad;
use Monadial\Nexus\Ddd\Aggregate\Event\Hook\BeforeEventStoreAppend;
use Monadial\Nexus\Ddd\Aggregate\Event\Hook\BeforeEventStoreDelete;
use Monadial\Nexus\Ddd\Aggregate\Event\Hook\BeforeEventStoreLoad;
use Monadial\Nexus\Ddd\Aggregate\Event\Hook\EventStoreAppendFailed;
use Monadial\Nexus\Ddd\Aggregate\Event\Hook\EventStoreHookEvent;
use Monadial\Nexus\Ddd\Aggregate\Hook\HookEvent;
use Monadial\Nexus\Ddd\Aggregate\Snapshot\Hook\AfterSnapshotDelete;
use Monadial\Nexus\Ddd\Aggregate\Snapshot\Hook\AfterSnapshotLoad;
use Monadial\Nexus\Ddd\Aggregate\Snapshot\Hook\AfterSnapshotSave;
use Monadial\Nexus\Ddd\Aggregate\Snapshot\Hook\BeforeSnapshotDelete;
use Monadial\Nexus\Ddd\Aggregate\Snapshot\Hook\BeforeSnapshotLoad;
use Monadial\Nexus\Ddd\Aggregate\Snapshot\Hook\BeforeSnapshotSave;
use Monadial\Nexus\Ddd\Aggregate\Snapshot\Hook\SnapshotHookEvent;
use Monadial\Nexus\Ddd\Aggregate\Snapshot\Hook\SnapshotSaveFailed;
use Monadial\Nexus\Ddd\Aggregate\Snapshot\Snapshot;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

#[CoversNothing]
final class HookEventHierarchyTest extends TestCase
{
    #[Test]
    public function eventStoreHooksExtendEventStoreHookEventAndHookEvent(): void
    {
        $streamId = new AggregateStreamId('App\\Order', 'order-1');

        $events = [
            new AfterEventStoreAppend($streamId, 1, []),
            new AfterEventStoreDelete($streamId, 1),
            new AfterEventStoreLoad($streamId, 1, 9, []),
            new BeforeEventStoreAppend($streamId, 0, []),
            new BeforeEventStoreDelete($streamId, 1),
            new BeforeEventStoreLoad($streamId, 5, 100),
            new EventStoreAppendFailed($streamId, 0, [], new RuntimeException('boom')),
        ];

        foreach ($events as $event) {
            self::assertInstanceOf(EventStoreHookEvent::class, $event);
            self::assertInstanceOf(HookEvent::class, $event);
            self::assertNotInstanceOf(SnapshotHookEvent::class, $event);
        }
    }

    #[Test]
    public function snapshotHooksExtendSnapshotHookEventAndHookEvent(): void
    {
        $streamId = new AggregateStreamId('App\\Order', 'order-1');
        $snapshot = new Snapshot(
            $streamId,
            5,
            new stdClass(),
            stdClass::class,
            1,
            new DateTimeImmutable('2026-05-08T12:00:00+00:00'),
        );

        $events = [
            new AfterSnapshotDelete($streamId, 5),
            new AfterSnapshotLoad($streamId, Option::none()),
            new AfterSnapshotSave($streamId, $snapshot),
            new BeforeSnapshotDelete($streamId, 5),
            new BeforeSnapshotLoad($streamId),
            new BeforeSnapshotSave($streamId, $snapshot),
            new SnapshotSaveFailed($streamId, $snapshot, new RuntimeException('boom')),
        ];

        foreach ($events as $event) {
            self::assertInstanceOf(SnapshotHookEvent::class, $event);
            self::assertInstanceOf(HookEvent::class, $event);
            self::assertNotInstanceOf(EventStoreHookEvent::class, $event);
        }
    }

    #[Test]
    public function streamIdInheritedFromHookEvent(): void
    {
        $streamId = new AggregateStreamId('App\\Order', 'order-1');
        $event = new BeforeEventStoreLoad($streamId, 1, 10);

        self::assertSame($streamId, $event->streamId);
    }
}
