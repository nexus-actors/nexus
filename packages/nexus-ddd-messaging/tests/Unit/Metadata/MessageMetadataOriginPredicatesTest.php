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
final class MessageMetadataOriginPredicatesTest extends TestCase
{
    private MessageMetadata $root;

    #[Test]
    public function isRootReturnsTrueWhenNoCausationId(): void
    {
        self::assertTrue($this->root->isRoot());
    }

    #[Test]
    public function isRootReturnsFalseWhenCausationIdPresent(): void
    {
        $child = $this->root->forCausedMessage(
            MessageId::generate(),
            new DateTimeImmutable('2026-05-07T10:01:00+00:00'),
        );

        self::assertFalse($child->isRoot());
    }

    #[Test]
    public function isCausedByReturnsTrueForDirectParent(): void
    {
        $child = $this->root->forCausedMessage(
            MessageId::generate(),
            new DateTimeImmutable('2026-05-07T10:01:00+00:00'),
        );

        self::assertTrue($child->isCausedBy($this->root->id));
    }

    #[Test]
    public function isCausedByReturnsFalseForUnrelatedId(): void
    {
        $child = $this->root->forCausedMessage(
            MessageId::generate(),
            new DateTimeImmutable('2026-05-07T10:01:00+00:00'),
        );
        $unrelated = MessageId::generate();

        self::assertFalse($child->isCausedBy($unrelated));
    }

    #[Test]
    public function isCausedByReturnsFalseWhenNoCausation(): void
    {
        self::assertFalse($this->root->isCausedBy(MessageId::generate()));
    }

    #[Test]
    public function correlatesToReturnsTrueForMatchingId(): void
    {
        $correlationId = MessageId::generate();
        $meta = new MessageMetadata(
            id: MessageId::generate(),
            occurredAt: new DateTimeImmutable('2026-05-07T10:00:00+00:00'),
            causationId: Option::none(),
            correlationId: Option::some($correlationId),
            conversationId: Option::none(),
            schemaVersion: 1,
            traceParent: Option::none(),
            traceState: Option::none(),
            expiresAt: Option::none(),
            vectorClock: Option::none(),
        );

        self::assertTrue($meta->correlatesTo($correlationId));
    }

    #[Test]
    public function correlatesToReturnsFalseWhenNoCorrelation(): void
    {
        self::assertFalse($this->root->correlatesTo(MessageId::generate()));
    }

    #[Test]
    public function isPartOfConversationReturnsTrueForMatchingId(): void
    {
        $conversationId = MessageId::generate();
        $meta = new MessageMetadata(
            id: MessageId::generate(),
            occurredAt: new DateTimeImmutable('2026-05-07T10:00:00+00:00'),
            causationId: Option::none(),
            correlationId: Option::none(),
            conversationId: Option::some($conversationId),
            schemaVersion: 1,
            traceParent: Option::none(),
            traceState: Option::none(),
            expiresAt: Option::none(),
            vectorClock: Option::none(),
        );

        self::assertTrue($meta->isPartOfConversation($conversationId));
    }

    #[Test]
    public function isPartOfConversationReturnsFalseWhenNoConversation(): void
    {
        self::assertFalse($this->root->isPartOfConversation(MessageId::generate()));
    }

    protected function setUp(): void
    {
        $now = new DateTimeImmutable('2026-05-07T10:00:00+00:00');
        $clock = new class ($now) implements ClockInterface {
            public function __construct(private DateTimeImmutable $now) {}

            public function now(): DateTimeImmutable {
return $this->now;
 }
        };
        $this->root = MessageMetadata::root($clock);
    }
}
