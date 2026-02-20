<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\Tests\Unit\Snapshot;

use DateTimeImmutable;
use Monadial\Nexus\Persistence\PersistenceId;
use Monadial\Nexus\Persistence\Snapshot\SnapshotEnvelope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use stdClass;

#[CoversClass(SnapshotEnvelope::class)]
final class SnapshotEnvelopeTest extends TestCase
{
    #[Test]
    public function constructsWithAllProperties(): void
    {
        $persistenceId = PersistenceId::of('order', 'order-123');
        $state = new stdClass();
        $state->total = 500;
        $timestamp = new DateTimeImmutable('2026-01-15T10:30:00+00:00');

        $envelope = new SnapshotEnvelope(
            persistenceId: $persistenceId,
            sequenceNr: 10,
            state: $state,
            stateType: 'OrderState',
            timestamp: $timestamp,
        );

        self::assertSame($persistenceId, $envelope->persistenceId);
        self::assertSame(10, $envelope->sequenceNr);
        self::assertSame($state, $envelope->state);
        self::assertSame('OrderState', $envelope->stateType);
        self::assertSame($timestamp, $envelope->timestamp);
    }

    #[Test]
    public function isReadonly(): void
    {
        $envelope = new SnapshotEnvelope(
            persistenceId: PersistenceId::of('order', 'order-1'),
            sequenceNr: 1,
            state: new stdClass(),
            stateType: 'OrderState',
            timestamp: new DateTimeImmutable(),
        );

        $reflection = new ReflectionClass($envelope);
        self::assertTrue($reflection->isReadOnly());
    }
}
