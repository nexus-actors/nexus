<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Tests\Unit\Snapshot\Hook;

use DateTimeImmutable;
use Monadial\Nexus\Ddd\Aggregate\Event\AggregateStreamId;
use Monadial\Nexus\Ddd\Aggregate\Snapshot\Hook\AfterSnapshotSave;
use Monadial\Nexus\Ddd\Aggregate\Snapshot\Snapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(AfterSnapshotSave::class)]
final class AfterSnapshotSaveTest extends TestCase
{
    #[Test]
    public function constructorExposesAllFieldsAsPublicReadonly(): void
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
        $event = new AfterSnapshotSave($streamId, $snapshot);

        self::assertSame($streamId, $event->streamId);
        self::assertSame($snapshot, $event->snapshot);
    }
}
