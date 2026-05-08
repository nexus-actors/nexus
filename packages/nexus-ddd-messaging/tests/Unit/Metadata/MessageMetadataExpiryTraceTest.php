<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Metadata;

use DateTimeImmutable;
use Fp\Functional\Option\Option;
use Monadial\Duration\FiniteDuration;
use Monadial\Nexus\Ddd\Messaging\Metadata\MessageMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

#[CoversClass(MessageMetadata::class)]
final class MessageMetadataExpiryTraceTest extends TestCase
{
    private MessageMetadata $base;
    private DateTimeImmutable $occurredAt;

    #[Test]
    public function hasTraceReturnsFalseWhenNoTraceParent(): void
    {
        self::assertFalse($this->base->hasTrace());
    }

    #[Test]
    public function hasTraceReturnsTrueWhenTraceParentSet(): void
    {
        $meta = $this->base->withTrace('00-trace-span-01', Option::none());

        self::assertTrue($meta->hasTrace());
    }

    #[Test]
    public function hasExpiryReturnsFalseWhenNoExpiresAt(): void
    {
        self::assertFalse($this->base->hasExpiry());
    }

    #[Test]
    public function hasExpiryReturnsTrueWhenExpiresAtSet(): void
    {
        $meta = $this->base->withExpiresAt(new DateTimeImmutable('2026-05-07T11:00:00+00:00'));

        self::assertTrue($meta->hasExpiry());
    }

    #[Test]
    public function isExpiredReturnsFalseWhenNoExpiry(): void
    {
        $now = new DateTimeImmutable('2026-05-07T12:00:00+00:00');

        self::assertFalse($this->base->isExpired($now));
    }

    #[Test]
    public function isExpiredReturnsFalseWhenNotYetExpired(): void
    {
        $meta = $this->base->withExpiresAt(new DateTimeImmutable('2026-05-07T11:00:00+00:00'));
        $now = new DateTimeImmutable('2026-05-07T10:30:00+00:00');

        self::assertFalse($meta->isExpired($now));
    }

    #[Test]
    public function isExpiredReturnsTrueWhenExpired(): void
    {
        $meta = $this->base->withExpiresAt(new DateTimeImmutable('2026-05-07T10:30:00+00:00'));
        $now = new DateTimeImmutable('2026-05-07T11:00:00+00:00');

        self::assertTrue($meta->isExpired($now));
    }

    #[Test]
    public function timeUntilExpiryReturnsNoneWhenNoExpiry(): void
    {
        $now = new DateTimeImmutable('2026-05-07T10:30:00+00:00');

        self::assertTrue($this->base->timeUntilExpiry($now)->isNone());
    }

    #[Test]
    public function timeUntilExpiryReturnsNoneWhenAlreadyExpired(): void
    {
        $meta = $this->base->withExpiresAt(new DateTimeImmutable('2026-05-07T10:30:00+00:00'));
        $now = new DateTimeImmutable('2026-05-07T11:00:00+00:00');

        self::assertTrue($meta->timeUntilExpiry($now)->isNone());
    }

    #[Test]
    public function timeUntilExpiryReturnsRemainingDuration(): void
    {
        $meta = $this->base->withExpiresAt(new DateTimeImmutable('2026-05-07T11:00:00+00:00'));
        $now = new DateTimeImmutable('2026-05-07T10:00:00+00:00');

        $result = $meta->timeUntilExpiry($now);
        self::assertTrue($result->isSome());
        self::assertInstanceOf(FiniteDuration::class, $result->get());
        self::assertSame(3600, $result->get()->toSeconds());
    }

    #[Test]
    public function ageAtComputesPositiveDuration(): void
    {
        $now = new DateTimeImmutable('2026-05-07T10:30:00+00:00');

        $duration = $this->base->ageAt($now);
        self::assertInstanceOf(FiniteDuration::class, $duration);
        self::assertSame(1800, $duration->toSeconds());
    }

    protected function setUp(): void
    {
        $this->occurredAt = new DateTimeImmutable('2026-05-07T10:00:00+00:00');
        $clock = new class ($this->occurredAt) implements ClockInterface {
            public function __construct(private DateTimeImmutable $now) {}

            public function now(): DateTimeImmutable {
return $this->now;
 }
        };

        $this->base = MessageMetadata::root($clock);
    }
}
