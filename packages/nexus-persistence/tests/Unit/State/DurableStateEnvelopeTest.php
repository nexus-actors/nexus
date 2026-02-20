<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\Tests\Unit\State;

use DateTimeImmutable;
use Monadial\Nexus\Persistence\PersistenceId;
use Monadial\Nexus\Persistence\State\DurableStateEnvelope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use stdClass;

#[CoversClass(DurableStateEnvelope::class)]
final class DurableStateEnvelopeTest extends TestCase
{
    #[Test]
    public function constructsWithAllProperties(): void
    {
        $persistenceId = PersistenceId::of('counter', 'counter-42');
        $state = new stdClass();
        $state->count = 42;
        $timestamp = new DateTimeImmutable('2026-01-15T10:30:00+00:00');

        $envelope = new DurableStateEnvelope(
            persistenceId: $persistenceId,
            version: 3,
            state: $state,
            stateType: 'CounterState',
            timestamp: $timestamp,
        );

        self::assertSame($persistenceId, $envelope->persistenceId);
        self::assertSame(3, $envelope->version);
        self::assertSame($state, $envelope->state);
        self::assertSame('CounterState', $envelope->stateType);
        self::assertSame($timestamp, $envelope->timestamp);
    }

    #[Test]
    public function isReadonly(): void
    {
        $envelope = new DurableStateEnvelope(
            persistenceId: PersistenceId::of('counter', 'counter-1'),
            version: 1,
            state: new stdClass(),
            stateType: 'CounterState',
            timestamp: new DateTimeImmutable(),
        );

        $reflection = new ReflectionClass($envelope);
        self::assertTrue($reflection->isReadOnly());
    }
}
