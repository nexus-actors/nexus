<?php

declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Fiber\Tests\Unit;

use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Exception\MailboxClosedException;
use Monadial\Nexus\Runtime\Exception\MailboxOverflowException;
use Monadial\Nexus\Runtime\Fiber\FiberMailbox;
use Monadial\Nexus\Runtime\Mailbox\EnqueueResult;
use Monadial\Nexus\Runtime\Mailbox\Mailbox;
use Monadial\Nexus\Runtime\Mailbox\MailboxConfig;
use Monadial\Nexus\Runtime\Mailbox\OverflowStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(FiberMailbox::class)]
final class FiberMailboxTest extends TestCase
{
    #[Test]
    public function it_implements_mailbox(): void
    {
        $mailbox = new FiberMailbox(MailboxConfig::unbounded());
        self::assertInstanceOf(Mailbox::class, $mailbox);
    }

    #[Test]
    public function enqueue_dequeue_fifo_order(): void
    {
        $mailbox = new FiberMailbox(MailboxConfig::unbounded());

        $env1 = $this->createMessage('msg1');
        $env2 = $this->createMessage('msg2');
        $env3 = $this->createMessage('msg3');

        (void) $mailbox->enqueue($env1);
        (void) $mailbox->enqueue($env2);
        (void) $mailbox->enqueue($env3);

        self::assertSame($env1, $mailbox->dequeue()->get());
        self::assertSame($env2, $mailbox->dequeue()->get());
        self::assertSame($env3, $mailbox->dequeue()->get());
    }

    #[Test]
    public function dequeue_returns_none_when_empty(): void
    {
        $mailbox = new FiberMailbox(MailboxConfig::unbounded());

        $result = $mailbox->dequeue();
        self::assertTrue($result->isNone());
    }

    #[Test]
    public function count_tracks_messages(): void
    {
        $mailbox = new FiberMailbox(MailboxConfig::unbounded());

        self::assertSame(0, $mailbox->count());

        (void) $mailbox->enqueue($this->createMessage('msg1'));
        self::assertSame(1, $mailbox->count());

        (void) $mailbox->enqueue($this->createMessage('msg2'));
        self::assertSame(2, $mailbox->count());

        $mailbox->dequeue();
        self::assertSame(1, $mailbox->count());
    }

    #[Test]
    public function is_empty_reflects_state(): void
    {
        $mailbox = new FiberMailbox(MailboxConfig::unbounded());

        self::assertTrue($mailbox->isEmpty());

        (void) $mailbox->enqueue($this->createMessage('msg'));
        self::assertFalse($mailbox->isEmpty());

        $mailbox->dequeue();
        self::assertTrue($mailbox->isEmpty());
    }

    #[Test]
    public function is_full_for_bounded_mailbox(): void
    {
        $mailbox = new FiberMailbox(
            MailboxConfig::bounded(2, OverflowStrategy::DropNewest),
        );

        self::assertFalse($mailbox->isFull());

        (void) $mailbox->enqueue($this->createMessage('msg1'));
        self::assertFalse($mailbox->isFull());

        (void) $mailbox->enqueue($this->createMessage('msg2'));
        self::assertTrue($mailbox->isFull());

        $mailbox->dequeue();
        self::assertFalse($mailbox->isFull());
    }

    #[Test]
    public function unbounded_mailbox_is_never_full(): void
    {
        $mailbox = new FiberMailbox(MailboxConfig::unbounded());

        for ($i = 0; $i < 100; $i++) {
            (void) $mailbox->enqueue($this->createMessage("msg{$i}"));
        }

        self::assertFalse($mailbox->isFull());
        self::assertSame(100, $mailbox->count());
    }

    #[Test]
    public function unbounded_mailbox_accepts_unlimited(): void
    {
        $mailbox = new FiberMailbox(MailboxConfig::unbounded());

        for ($i = 0; $i < 50; $i++) {
            $result = $mailbox->enqueue($this->createMessage("msg{$i}"));
            self::assertSame(EnqueueResult::Accepted, $result);
        }

        self::assertSame(50, $mailbox->count());
    }

    #[Test]
    public function bounded_drop_newest_drops_incoming_when_full(): void
    {
        $mailbox = new FiberMailbox(
            MailboxConfig::bounded(2, OverflowStrategy::DropNewest),
        );

        $env1 = $this->createMessage('msg1');
        $env2 = $this->createMessage('msg2');
        $env3 = $this->createMessage('msg3');

        self::assertSame(EnqueueResult::Accepted, $mailbox->enqueue($env1));
        self::assertSame(EnqueueResult::Accepted, $mailbox->enqueue($env2));
        self::assertSame(EnqueueResult::Dropped, $mailbox->enqueue($env3));

        self::assertSame(2, $mailbox->count());
        // Original messages remain, newest was dropped
        self::assertSame($env1, $mailbox->dequeue()->get());
        self::assertSame($env2, $mailbox->dequeue()->get());
    }

    #[Test]
    public function bounded_drop_oldest_drops_oldest_when_full(): void
    {
        $mailbox = new FiberMailbox(
            MailboxConfig::bounded(2, OverflowStrategy::DropOldest),
        );

        $env1 = $this->createMessage('msg1');
        $env2 = $this->createMessage('msg2');
        $env3 = $this->createMessage('msg3');

        self::assertSame(EnqueueResult::Accepted, $mailbox->enqueue($env1));
        self::assertSame(EnqueueResult::Accepted, $mailbox->enqueue($env2));
        self::assertSame(EnqueueResult::Accepted, $mailbox->enqueue($env3));

        self::assertSame(2, $mailbox->count());
        // Oldest was dropped, msg2 and msg3 remain
        self::assertSame($env2, $mailbox->dequeue()->get());
        self::assertSame($env3, $mailbox->dequeue()->get());
    }

    #[Test]
    public function bounded_throw_exception_throws_when_full(): void
    {
        $mailbox = new FiberMailbox(
            MailboxConfig::bounded(2, OverflowStrategy::ThrowException),
        );

        (void) $mailbox->enqueue($this->createMessage('msg1'));
        (void) $mailbox->enqueue($this->createMessage('msg2'));

        $this->expectException(MailboxOverflowException::class);
        (void) $mailbox->enqueue($this->createMessage('msg3'));
    }

    #[Test]
    public function bounded_backpressure_returns_backpressured_when_full(): void
    {
        $mailbox = new FiberMailbox(
            MailboxConfig::bounded(2, OverflowStrategy::Backpressure),
        );

        (void) $mailbox->enqueue($this->createMessage('msg1'));
        (void) $mailbox->enqueue($this->createMessage('msg2'));

        $result = $mailbox->enqueue($this->createMessage('msg3'));
        self::assertSame(EnqueueResult::Backpressured, $result);
        self::assertSame(2, $mailbox->count());
    }

    #[Test]
    public function close_prevents_enqueue(): void
    {
        $mailbox = new FiberMailbox(MailboxConfig::unbounded());
        $mailbox->close();

        $this->expectException(MailboxClosedException::class);
        (void) $mailbox->enqueue($this->createMessage('msg'));
    }

    #[Test]
    public function close_allows_dequeue_of_remaining_messages(): void
    {
        $mailbox = new FiberMailbox(MailboxConfig::unbounded());

        $env = $this->createMessage('msg');
        (void) $mailbox->enqueue($env);

        $mailbox->close();

        // Remaining messages can still be drained
        self::assertSame($env, $mailbox->dequeue()->get());
        self::assertTrue($mailbox->dequeue()->isNone());
    }

    #[Test]
    public function dequeue_blocking_returns_immediately_when_message_available(): void
    {
        $mailbox = new FiberMailbox(MailboxConfig::unbounded());

        $env = $this->createMessage('msg');
        (void) $mailbox->enqueue($env);

        $result = $mailbox->dequeueBlocking(Duration::millis(100));
        self::assertSame($env, $result);
    }

    #[Test]
    public function dequeue_blocking_throws_when_closed_and_empty(): void
    {
        $mailbox = new FiberMailbox(MailboxConfig::unbounded());
        $mailbox->close();

        $this->expectException(MailboxClosedException::class);
        $mailbox->dequeueBlocking(Duration::millis(100));
    }

    private function createMessage(string $label): object
    {
        $message = new stdClass();
        $message->label = $label;

        return $message;
    }
}
