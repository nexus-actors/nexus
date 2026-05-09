<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Tests\Unit\Snapshot;

use DateTimeImmutable;
use Monadial\Nexus\Ddd\Aggregate\Event\AggregateStreamId;
use Monadial\Nexus\Ddd\Aggregate\Snapshot\Snapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(Snapshot::class)]
final class SnapshotTest extends TestCase
{
    #[Test]
    public function constructorExposesAllFieldsAsPublicReadonly(): void
    {
        $streamId = new AggregateStreamId('App\\Order', 'order-1');
        $state = new stdClass();
        $occurredAt = new DateTimeImmutable('2026-05-08T12:00:00+00:00');
        $snapshot = new Snapshot($streamId, 7, $state, stdClass::class, 2, $occurredAt, ['key' => 'value']);

        self::assertSame($streamId, $snapshot->streamId);
        self::assertSame(7, $snapshot->sequenceNr);
        self::assertSame($state, $snapshot->state);
        self::assertSame(stdClass::class, $snapshot->stateType);
        self::assertSame(2, $snapshot->stateVersion);
        self::assertSame($occurredAt, $snapshot->occurredAt);
        self::assertSame(['key' => 'value'], $snapshot->metadata);
    }

    #[Test]
    public function constructorDefaultsMetadataToEmptyArray(): void
    {
        $snapshot = new Snapshot(
            new AggregateStreamId('App\\Order', 'order-1'),
            1,
            new stdClass(),
            stdClass::class,
            1,
            new DateTimeImmutable('2026-05-08T12:00:00+00:00'),
        );

        self::assertSame([], $snapshot->metadata);
    }
}
