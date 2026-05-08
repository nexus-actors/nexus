<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Envelope;

use DateTimeImmutable;
use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Monadial\Nexus\Ddd\Messaging\Envelope\Stamp\PerCorrelationKeyOrdered;
use Monadial\Nexus\Ddd\Messaging\Identity\MessageId;
use Monadial\Nexus\Ddd\Messaging\Metadata\MessageMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

final readonly class FixtureMessage
{
    public function __construct(public string $payload) {}
}

#[CoversClass(Envelope::class)]
final class EnvelopeTest extends TestCase
{
    #[Test]
    public function constructorStoresMessageMetadataAndEmptyStampsByDefault(): void
    {
        $msg = new FixtureMessage('hello');
        $meta = MessageMetadata::root($this->fixedClock());
        $env = new Envelope($msg, $meta);

        self::assertSame($msg, $env->message);
        self::assertSame($meta, $env->metadata);
        self::assertSame([], $env->stamps);
    }

    #[Test]
    public function getReturnsNoneWhenStampMissing(): void
    {
        $env = new Envelope(
            new FixtureMessage('x'),
            MessageMetadata::root($this->fixedClock()),
        );
        self::assertTrue($env->get(PerCorrelationKeyOrdered::class)->isNone());
    }

    #[Test]
    public function withAddsStampAndReturnsNewInstance(): void
    {
        $original = new Envelope(
            new FixtureMessage('x'),
            MessageMetadata::root($this->fixedClock()),
        );
        $stamp = new PerCorrelationKeyOrdered('order-7');
        $next = $original->with($stamp);

        self::assertSame([], $original->stamps);
        self::assertNotSame($original, $next);
        self::assertSame($stamp, $next->get(PerCorrelationKeyOrdered::class)->getOrElse(
            new PerCorrelationKeyOrdered('miss'),
        ));
    }

    #[Test]
    public function withReplacesStampOfSameClass(): void
    {
        $env = new Envelope(
            new FixtureMessage('x'),
            MessageMetadata::root($this->fixedClock()),
        );
        $a = new PerCorrelationKeyOrdered('A');
        $b = new PerCorrelationKeyOrdered('B');
        $next = $env->with($a)->with($b);

        $found = $next->get(PerCorrelationKeyOrdered::class)->getOrElse(
            new PerCorrelationKeyOrdered('miss'),
        );
        self::assertSame('B', $found->correlationKey);
    }

    #[Test]
    public function metadataIdRoundTripsViaConstructor(): void
    {
        $id = MessageId::generate();
        $meta = new MessageMetadata(
            id: $id,
            occurredAt: new DateTimeImmutable('2026-05-07T10:00:00+00:00'),
            causationId: Option::none(),
            correlationId: Option::none(),
            conversationId: Option::none(),
            schemaVersion: 1,
            traceParent: Option::none(),
            traceState: Option::none(),
            expiresAt: Option::none(),
            vectorClock: Option::none(),
        );
        $env = new Envelope(new FixtureMessage('x'), $meta);
        self::assertSame($id, $env->metadata->id);
    }

    private function fixedClock(): ClockInterface
    {
        $now = new DateTimeImmutable('2026-05-07T10:00:00+00:00');

        return new class ($now) implements ClockInterface {
            public function __construct(private DateTimeImmutable $now) {}

            public function now(): DateTimeImmutable
            {
                return $this->now;
            }
        };
    }
}
