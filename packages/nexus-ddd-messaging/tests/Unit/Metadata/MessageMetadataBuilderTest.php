<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Metadata;

use DateTimeImmutable;
use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Messaging\Clock\VectorClock;
use Monadial\Nexus\Ddd\Messaging\Identity\MessageId;
use Monadial\Nexus\Ddd\Messaging\Metadata\MessageMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

#[CoversClass(MessageMetadata::class)]
final class MessageMetadataBuilderTest extends TestCase
{
    private MessageMetadata $base;

    protected function setUp(): void
    {
        $now = new DateTimeImmutable('2026-05-07T10:00:00+00:00');
        $clock = new class ($now) implements ClockInterface {
            public function __construct(private DateTimeImmutable $now) {}

            public function now(): DateTimeImmutable { return $this->now; }
        };

        $this->base = MessageMetadata::root($clock);
    }

    #[Test]
    public function withTraceSetsBothFields(): void
    {
        $updated = $this->base->withTrace('00-trace-span-01', Option::some('vendor=foo'));

        self::assertSame($this->base->id, $updated->id);
        self::assertTrue($updated->traceParent->isSome());
        self::assertSame('00-trace-span-01', $updated->traceParent->get());
        self::assertTrue($updated->traceState->isSome());
        self::assertSame('vendor=foo', $updated->traceState->get());
    }

    #[Test]
    public function withTraceSetsTraceStateAsNoneWhenAbsent(): void
    {
        $updated = $this->base->withTrace('00-trace-span-01', Option::none());

        self::assertTrue($updated->traceParent->isSome());
        self::assertTrue($updated->traceState->isNone());
    }

    #[Test]
    public function withExpiresAtSetsExpiry(): void
    {
        $expires = new DateTimeImmutable('2026-05-07T11:00:00+00:00');
        $updated = $this->base->withExpiresAt($expires);

        self::assertTrue($updated->expiresAt->isSome());
        self::assertSame($expires, $updated->expiresAt->get());
        self::assertSame($this->base->id, $updated->id);
    }

    #[Test]
    public function withVectorClockSetsVectorClock(): void
    {
        $vc = VectorClock::empty();
        $updated = $this->base->withVectorClock($vc);

        self::assertTrue($updated->vectorClock->isSome());
        self::assertSame($vc, $updated->vectorClock->get());
        self::assertSame($this->base->id, $updated->id);
    }

    #[Test]
    public function withSchemaVersionSetsVersion(): void
    {
        $updated = $this->base->withSchemaVersion(5);

        self::assertSame(5, $updated->schemaVersion);
        self::assertSame($this->base->id, $updated->id);
    }

    #[Test]
    public function buildersAreImmutable(): void
    {
        $updated = $this->base->withSchemaVersion(42);

        self::assertSame(1, $this->base->schemaVersion);
        self::assertSame(42, $updated->schemaVersion);
    }
}
