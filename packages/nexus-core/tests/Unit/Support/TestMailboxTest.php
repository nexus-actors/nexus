<?php
declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Unit\Support;

use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Core\Exception\MailboxClosedException;
use Monadial\Nexus\Core\Exception\MailboxOverflowException;
use Monadial\Nexus\Core\Mailbox\EnqueueResult;
use Monadial\Nexus\Core\Mailbox\Envelope;
use Monadial\Nexus\Core\Mailbox\OverflowStrategy;
use Monadial\Nexus\Core\Tests\Support\TestMailbox;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(TestMailbox::class)]
final class TestMailboxTest extends TestCase
{
    #[Test]
    public function unboundedFactoryCreatesUnboundedMailbox(): void
    {
        $mailbox = TestMailbox::unbounded();

        self::assertTrue($mailbox->isEmpty());
        self::assertSame(0, $mailbox->count());
        self::assertFalse($mailbox->isFull());
    }

    #[Test]
    public function boundedFactoryCreatesBoundedMailbox(): void
    {
        $mailbox = TestMailbox::bounded(5);

        self::assertTrue($mailbox->isEmpty());
        self::assertSame(0, $mailbox->count());
    }

    #[Test]
    public function enqueueAndDequeueFIFO(): void
    {
        $mailbox = TestMailbox::unbounded();
        $msg1 = (object) ['id' => 1];
        $msg2 = (object) ['id' => 2];
        $msg3 = (object) ['id' => 3];

        $mailbox->enqueue($this->createEnvelopeWithMessage($msg1));
        $mailbox->enqueue($this->createEnvelopeWithMessage($msg2));
        $mailbox->enqueue($this->createEnvelopeWithMessage($msg3));

        self::assertSame(3, $mailbox->count());

        $first = $mailbox->dequeue()->get();
        self::assertSame($msg1, $first->message);

        $second = $mailbox->dequeue()->get();
        self::assertSame($msg2, $second->message);

        $third = $mailbox->dequeue()->get();
        self::assertSame($msg3, $third->message);
    }

    #[Test]
    public function dequeueReturnsNoneWhenEmpty(): void
    {
        $mailbox = TestMailbox::unbounded();

        $result = $mailbox->dequeue();

        self::assertTrue($result->isNone());
    }

    #[Test]
    public function enqueueReturnsAccepted(): void
    {
        $mailbox = TestMailbox::unbounded();

        $result = $mailbox->enqueue($this->createEnvelope());

        self::assertSame(EnqueueResult::Accepted, $result);
    }

    #[Test]
    public function boundedMailboxReportsFullWhenAtCapacity(): void
    {
        $mailbox = TestMailbox::bounded(2);

        $mailbox->enqueue($this->createEnvelope());
        $mailbox->enqueue($this->createEnvelope());

        self::assertTrue($mailbox->isFull());
        self::assertSame(2, $mailbox->count());
    }

    #[Test]
    public function boundedDropNewestDropsNewMessage(): void
    {
        $mailbox = TestMailbox::bounded(2, OverflowStrategy::DropNewest);

        $mailbox->enqueue($this->createEnvelope());
        $mailbox->enqueue($this->createEnvelope());
        $result = $mailbox->enqueue($this->createEnvelope());

        self::assertSame(EnqueueResult::Dropped, $result);
        self::assertSame(2, $mailbox->count());
    }

    #[Test]
    public function boundedDropOldestDropsOldestMessage(): void
    {
        $mailbox = TestMailbox::bounded(2, OverflowStrategy::DropOldest);
        $msg1 = (object) ['id' => 1];
        $msg2 = (object) ['id' => 2];
        $msg3 = (object) ['id' => 3];

        $mailbox->enqueue($this->createEnvelopeWithMessage($msg1));
        $mailbox->enqueue($this->createEnvelopeWithMessage($msg2));
        $result = $mailbox->enqueue($this->createEnvelopeWithMessage($msg3));

        self::assertSame(EnqueueResult::Accepted, $result);
        self::assertSame(2, $mailbox->count());

        // msg1 was dropped, msg2 should be first
        $first = $mailbox->dequeue()->get();
        self::assertSame($msg2, $first->message);

        $second = $mailbox->dequeue()->get();
        self::assertSame($msg3, $second->message);
    }

    #[Test]
    public function boundedBackpressureReturnsBackpressured(): void
    {
        $mailbox = TestMailbox::bounded(1, OverflowStrategy::Backpressure);

        $mailbox->enqueue($this->createEnvelope());
        $result = $mailbox->enqueue($this->createEnvelope());

        self::assertSame(EnqueueResult::Backpressured, $result);
        self::assertSame(1, $mailbox->count());
    }

    #[Test]
    public function boundedThrowExceptionThrowsOnOverflow(): void
    {
        $mailbox = TestMailbox::bounded(1, OverflowStrategy::ThrowException);

        $mailbox->enqueue($this->createEnvelope());

        $this->expectException(MailboxOverflowException::class);
        $mailbox->enqueue($this->createEnvelope());
    }

    #[Test]
    public function closeMarksMailboxAsClosed(): void
    {
        $mailbox = TestMailbox::unbounded();

        self::assertFalse($mailbox->isClosed());

        $mailbox->close();

        self::assertTrue($mailbox->isClosed());
    }

    #[Test]
    public function enqueueOnClosedMailboxThrows(): void
    {
        $mailbox = TestMailbox::unbounded();
        $mailbox->close();

        $this->expectException(MailboxClosedException::class);
        $mailbox->enqueue($this->createEnvelope());
    }

    #[Test]
    public function dequeueBlockingReturnsNextEnvelope(): void
    {
        $mailbox = TestMailbox::unbounded();
        $msg = (object) ['id' => 42];
        $mailbox->enqueue($this->createEnvelopeWithMessage($msg));

        $envelope = $mailbox->dequeueBlocking(Duration::seconds(1));

        self::assertSame($msg, $envelope->message);
    }

    #[Test]
    public function dequeueBlockingOnEmptyThrows(): void
    {
        $mailbox = TestMailbox::unbounded();

        $this->expectException(MailboxClosedException::class);
        $mailbox->dequeueBlocking(Duration::seconds(1));
    }

    #[Test]
    public function dequeueBlockingOnClosedAndEmptyThrows(): void
    {
        $mailbox = TestMailbox::unbounded();
        $mailbox->close();

        $this->expectException(MailboxClosedException::class);
        $mailbox->dequeueBlocking(Duration::seconds(1));
    }

    #[Test]
    public function isEmptyReportsCorrectly(): void
    {
        $mailbox = TestMailbox::unbounded();

        self::assertTrue($mailbox->isEmpty());

        $mailbox->enqueue($this->createEnvelope());

        self::assertFalse($mailbox->isEmpty());

        $mailbox->dequeue();

        self::assertTrue($mailbox->isEmpty());
    }

    #[Test]
    public function countTracksMessages(): void
    {
        $mailbox = TestMailbox::unbounded();

        self::assertSame(0, $mailbox->count());

        $mailbox->enqueue($this->createEnvelope());
        self::assertSame(1, $mailbox->count());

        $mailbox->enqueue($this->createEnvelope());
        self::assertSame(2, $mailbox->count());

        $mailbox->dequeue();
        self::assertSame(1, $mailbox->count());
    }

    private function createEnvelope(string $content = 'hello'): Envelope
    {
        return Envelope::of(
            new stdClass(),
            ActorPath::fromString('/sender'),
            ActorPath::fromString('/target'),
        );
    }

    private function createEnvelopeWithMessage(object $message): Envelope
    {
        return Envelope::of(
            $message,
            ActorPath::fromString('/sender'),
            ActorPath::fromString('/target'),
        );
    }
}
