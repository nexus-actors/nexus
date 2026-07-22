<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Unit\Actor;

use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\DeadLetterRef;
use Monadial\Nexus\Core\Actor\LocalActorRef;
use Monadial\Nexus\Core\Mailbox\Envelope;
use Monadial\Nexus\Core\Tests\Support\TestMailbox;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Monadial\Nexus\Observability\NoopObservability;
use Monadial\Nexus\Runtime\Mailbox\OverflowStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * OPS-001: fire-and-forget deliveries that the mailbox cannot accept must be routed
 * to dead letters, not silently dropped, so every failure path is observable.
 */
#[CoversClass(LocalActorRef::class)]
final class LocalActorRefDeadLetterTest extends TestCase
{
    #[Test]
    public function tellRoutesToDeadLettersWhenMailboxIsClosed(): void
    {
        $mailbox = TestMailbox::unbounded();
        $mailbox->close();
        $deadLetters = new DeadLetterRef();
        $ref = $this->refFor($mailbox, $deadLetters);
        $message = new stdClass();

        $ref->tell($message);

        self::assertSame([$message], $deadLetters->captured());
        self::assertSame(1, $deadLetters->total());
    }

    #[Test]
    public function tellRoutesToDeadLettersWhenMailboxDropsTheMessage(): void
    {
        // A bounded DropNewest mailbox at capacity drops the incoming message.
        $mailbox = TestMailbox::bounded(1, OverflowStrategy::DropNewest);
        $deadLetters = new DeadLetterRef();
        $ref = $this->refFor($mailbox, $deadLetters);

        $ref->tell(new stdClass()); // fills the single slot — accepted
        $dropped = new stdClass();
        $ref->tell($dropped);       // dropped by DropNewest — must dead-letter

        self::assertSame([$dropped], $deadLetters->captured());
    }

    #[Test]
    public function tellDoesNotDeadLetterWhenAccepted(): void
    {
        $deadLetters = new DeadLetterRef();
        $ref = $this->refFor(TestMailbox::unbounded(), $deadLetters);

        $ref->tell(new stdClass());

        self::assertSame([], $deadLetters->captured());
        self::assertSame(0, $deadLetters->total());
    }

    #[Test]
    public function offerDoesNotDeadLetterSoTheCallerCanDecide(): void
    {
        // offer() returns the outcome for the caller to handle (e.g. broker redelivery);
        // it must NOT also dead-letter, or a dropped message would be double-handled.
        $mailbox = TestMailbox::unbounded();
        $mailbox->close();
        $deadLetters = new DeadLetterRef();
        $ref = $this->refFor($mailbox, $deadLetters);

        $_ = $ref->offer(new stdClass());

        self::assertSame([], $deadLetters->captured());
    }

    #[Test]
    public function enqueueEnvelopeRoutesToDeadLettersWhenMailboxIsClosed(): void
    {
        $mailbox = TestMailbox::unbounded();
        $mailbox->close();
        $deadLetters = new DeadLetterRef();
        $ref = $this->refFor($mailbox, $deadLetters);
        $inner = new stdClass();
        $envelope = Envelope::of($inner, ActorPath::root(), ActorPath::fromString('/user/test'));

        $ref->enqueueEnvelope($envelope);

        self::assertSame([$inner], $deadLetters->captured());
    }

    #[Test]
    public function tellWithoutDeadLettersConfiguredJustDrops(): void
    {
        // Backward compatible: with no dead-letters sink, a dropped tell() is a no-op.
        $mailbox = TestMailbox::unbounded();
        $mailbox->close();
        $ref = new LocalActorRef(
            ActorPath::fromString('/user/test'),
            $mailbox,
            static fn(): bool => true,
            new TestRuntime(),
            new NoopObservability(),
        );

        $ref->tell(new stdClass());

        $this->expectNotToPerformAssertions();
    }

    private function refFor(TestMailbox $mailbox, DeadLetterRef $deadLetters): LocalActorRef
    {
        return new LocalActorRef(
            ActorPath::fromString('/user/test'),
            $mailbox,
            static fn(): bool => true,
            new TestRuntime(),
            new NoopObservability(),
            $deadLetters,
        );
    }
}
