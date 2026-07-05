<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Unit\Actor;

use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\BackpressureCapable;
use Monadial\Nexus\Core\Actor\LocalActorRef;
use Monadial\Nexus\Core\Mailbox\Envelope;
use Monadial\Nexus\Core\Tests\Support\TestMailbox;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Monadial\Nexus\Observability\NoopObservability;
use Monadial\Nexus\Runtime\Mailbox\EnqueueResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(LocalActorRef::class)]
final class LocalActorRefOfferTest extends TestCase
{
    #[Test]
    public function offerReturnsAcceptedWhenMailboxAccepts(): void
    {
        $ref = $this->makeRef();

        self::assertInstanceOf(BackpressureCapable::class, $ref);
        self::assertSame(EnqueueResult::Accepted, $ref->offer(new stdClass()));
    }

    #[Test]
    public function offerReturnsDroppedWhenMailboxIsClosed(): void
    {
        [$ref, $mailbox] = $this->makeRefWithMailbox();
        $mailbox->close();

        self::assertSame(EnqueueResult::Dropped, $ref->offer(new stdClass()));
    }

    #[Test]
    public function offerEnvelopeReturnsAcceptedWhenMailboxAccepts(): void
    {
        $ref = $this->makeRef();
        $envelope = Envelope::of(new stdClass(), ActorPath::root(), ActorPath::fromString('/user/test'));

        self::assertSame(EnqueueResult::Accepted, $ref->offerEnvelope($envelope));
    }

    #[Test]
    public function offerEnvelopeReturnsDroppedWhenMailboxIsClosed(): void
    {
        [$ref, $mailbox] = $this->makeRefWithMailbox();
        $mailbox->close();
        $envelope = Envelope::of(new stdClass(), ActorPath::root(), ActorPath::fromString('/user/test'));

        self::assertSame(EnqueueResult::Dropped, $ref->offerEnvelope($envelope));
    }

    #[Test]
    public function offerEnvelopePreservesEnvelopeIntact(): void
    {
        [$ref, $mailbox] = $this->makeRefWithMailbox();
        $inner = new stdClass();
        $envelope = Envelope::of($inner, ActorPath::fromString('/user/sender'), ActorPath::fromString('/user/test'))
            ->withCorrelationId('test-corr-id');

        $result = $ref->offerEnvelope($envelope);

        self::assertSame(EnqueueResult::Accepted, $result);

        $dequeued = $mailbox->dequeue();

        self::assertNotNull($dequeued);
        self::assertSame($inner, $dequeued->message);
        self::assertSame('test-corr-id', $dequeued->correlationId);
    }

    private function makeRef(): LocalActorRef
    {
        return new LocalActorRef(
            ActorPath::fromString('/user/test'),
            TestMailbox::unbounded(),
            static fn(): bool => true,
            new TestRuntime(),
            new NoopObservability(),
        );
    }

    /** @return array{LocalActorRef, TestMailbox} */
    private function makeRefWithMailbox(): array
    {
        $mailbox = TestMailbox::unbounded();

        return [
            new LocalActorRef(
                ActorPath::fromString('/user/test'),
                $mailbox,
                static fn(): bool => true,
                new TestRuntime(),
                new NoopObservability(),
            ),
            $mailbox,
        ];
    }
}
