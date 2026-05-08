<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Metadata;

use DateTimeImmutable;
use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Messaging\Identity\MessageId;
use Monadial\Nexus\Ddd\Messaging\Metadata\MessageMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

#[CoversClass(MessageMetadata::class)]
final class MessageMetadataDerivationTest extends TestCase
{
    private MessageMetadata $root;
    private DateTimeImmutable $rootTime;

    protected function setUp(): void
    {
        $this->rootTime = new DateTimeImmutable('2026-05-07T10:00:00+00:00');
        $clock = new class ($this->rootTime) implements ClockInterface {
            public function __construct(private DateTimeImmutable $now) {}

            public function now(): DateTimeImmutable { return $this->now; }
        };

        $this->root = MessageMetadata::root($clock);
    }

    #[Test]
    public function causationIdIsSetToParentId(): void
    {
        $childId = MessageId::generate();
        $childTime = new DateTimeImmutable('2026-05-07T10:01:00+00:00');

        $child = $this->root->forCausedMessage($childId, $childTime);

        self::assertTrue($child->causationId->isSome());
        self::assertTrue($child->causationId->get()->equals($this->root->id));
    }

    #[Test]
    public function correlationIdIsInitializedToParentIdWhenParentHasNone(): void
    {
        $childId = MessageId::generate();
        $childTime = new DateTimeImmutable('2026-05-07T10:01:00+00:00');

        $child = $this->root->forCausedMessage($childId, $childTime);

        self::assertTrue($child->correlationId->isSome());
        self::assertTrue($child->correlationId->get()->equals($this->root->id));
    }

    #[Test]
    public function conversationIdIsInitializedToParentIdWhenParentHasNone(): void
    {
        $childId = MessageId::generate();
        $childTime = new DateTimeImmutable('2026-05-07T10:01:00+00:00');

        $child = $this->root->forCausedMessage($childId, $childTime);

        self::assertTrue($child->conversationId->isSome());
        self::assertTrue($child->conversationId->get()->equals($this->root->id));
    }

    #[Test]
    public function correlationIdPropagatesFromParentWhenParentHasOne(): void
    {
        $correlationId = MessageId::generate();
        $parent = new MessageMetadata(
            id: MessageId::generate(),
            occurredAt: $this->rootTime,
            causationId: Option::none(),
            correlationId: Option::some($correlationId),
            conversationId: Option::none(),
            schemaVersion: 1,
            traceParent: Option::none(),
            traceState: Option::none(),
            expiresAt: Option::none(),
            vectorClock: Option::none(),
        );

        $child = $parent->forCausedMessage(MessageId::generate(), new DateTimeImmutable('2026-05-07T10:01:00+00:00'));

        self::assertTrue($child->correlationId->isSome());
        self::assertTrue($child->correlationId->get()->equals($correlationId));
    }

    #[Test]
    public function conversationIdPropagatesFromParentWhenParentHasOne(): void
    {
        $conversationId = MessageId::generate();
        $parent = new MessageMetadata(
            id: MessageId::generate(),
            occurredAt: $this->rootTime,
            causationId: Option::none(),
            correlationId: Option::none(),
            conversationId: Option::some($conversationId),
            schemaVersion: 1,
            traceParent: Option::none(),
            traceState: Option::none(),
            expiresAt: Option::none(),
            vectorClock: Option::none(),
        );

        $child = $parent->forCausedMessage(MessageId::generate(), new DateTimeImmutable('2026-05-07T10:01:00+00:00'));

        self::assertTrue($child->conversationId->isSome());
        self::assertTrue($child->conversationId->get()->equals($conversationId));
    }

    #[Test]
    public function schemaVersionPropagates(): void
    {
        $parent = $this->root->withSchemaVersion(7);
        $child = $parent->forCausedMessage(MessageId::generate(), new DateTimeImmutable('2026-05-07T10:01:00+00:00'));

        self::assertSame(7, $child->schemaVersion);
    }

    #[Test]
    public function traceContextPropagates(): void
    {
        $parent = $this->root->withTrace('00-trace-span-01', Option::some('vendor=foo'));
        $child = $parent->forCausedMessage(MessageId::generate(), new DateTimeImmutable('2026-05-07T10:01:00+00:00'));

        self::assertTrue($child->traceParent->isSome());
        self::assertSame('00-trace-span-01', $child->traceParent->get());
        self::assertTrue($child->traceState->isSome());
    }

    #[Test]
    public function expiresAtIsAlwaysNoneInDerivedMessage(): void
    {
        $parent = $this->root->withExpiresAt(new DateTimeImmutable('2026-05-07T12:00:00+00:00'));
        $child = $parent->forCausedMessage(MessageId::generate(), new DateTimeImmutable('2026-05-07T10:01:00+00:00'));

        self::assertTrue($child->expiresAt->isNone());
    }

    #[Test]
    public function newIdAndTimeAreAssigned(): void
    {
        $newId = MessageId::generate();
        $newTime = new DateTimeImmutable('2026-05-07T10:01:00+00:00');

        $child = $this->root->forCausedMessage($newId, $newTime);

        self::assertSame($newId, $child->id);
        self::assertSame($newTime, $child->occurredAt);
    }
}
