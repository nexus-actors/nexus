<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Context;

use DateTimeImmutable;
use Monadial\Nexus\Ddd\Messaging\Context\MessageContext;
use Monadial\Nexus\Ddd\Messaging\Envelope\Stamp\PerCorrelationKeyOrdered;
use Monadial\Nexus\Ddd\Messaging\Metadata\MessageMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

#[CoversClass(MessageContext::class)]
final class MessageContextTest extends TestCase
{
    #[Test]
    public function exposesMetadataAndDefaultsStampsToEmpty(): void
    {
        $meta = MessageMetadata::root($this->fixedClock());
        $ctx = new MessageContext($meta);
        self::assertSame($meta, $ctx->metadata);
        self::assertSame([], $ctx->stamps);
    }

    #[Test]
    public function stampReturnsNoneWhenAbsent(): void
    {
        $ctx = new MessageContext(MessageMetadata::root($this->fixedClock()));
        self::assertTrue($ctx->stamp(PerCorrelationKeyOrdered::class)->isNone());
    }

    #[Test]
    public function stampReturnsSomeWhenPresent(): void
    {
        $stamp = new PerCorrelationKeyOrdered('order-1');
        $ctx = new MessageContext(
            MessageMetadata::root($this->fixedClock()),
            [PerCorrelationKeyOrdered::class => $stamp],
        );
        self::assertSame(
            $stamp,
            $ctx->stamp(PerCorrelationKeyOrdered::class)->getOrElse(new PerCorrelationKeyOrdered('miss')),
        );
    }

    private function fixedClock(): ClockInterface
    {
        $now = new DateTimeImmutable('2026-05-07T10:00:00+00:00');

        return new class ($now) implements ClockInterface {
            public function __construct(private DateTimeImmutable $now) {}

            public function now(): DateTimeImmutable {
return $this->now;
 }
        };
    }
}
