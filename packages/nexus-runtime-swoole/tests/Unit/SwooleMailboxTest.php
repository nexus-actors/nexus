<?php

declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Swoole\Tests\Unit;

use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Core\Exception\MailboxClosedException;
use Monadial\Nexus\Core\Exception\MailboxOverflowException;
use Monadial\Nexus\Core\Mailbox\EnqueueResult;
use Monadial\Nexus\Core\Mailbox\Envelope;
use Monadial\Nexus\Core\Mailbox\Mailbox;
use Monadial\Nexus\Core\Mailbox\MailboxConfig;
use Monadial\Nexus\Core\Mailbox\OverflowStrategy;
use Monadial\Nexus\Runtime\Swoole\SwooleMailbox;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Swoole\Coroutine;
use Throwable;

use function Swoole\Coroutine\run;

#[CoversClass(SwooleMailbox::class)]
final class SwooleMailboxTest extends TestCase
{
    #[Test]
    public function it_implements_mailbox(): void
    {
        run(static function (): void {
            $mailbox = new SwooleMailbox(MailboxConfig::unbounded(), ActorPath::root());
            self::assertInstanceOf(Mailbox::class, $mailbox);
        });
    }

    #[Test]
    public function enqueue_dequeue_fifo_order(): void
    {
        run(function (): void {
            $mailbox = new SwooleMailbox(MailboxConfig::unbounded(), ActorPath::root());

            $env1 = $this->createEnvelope();
            $env2 = $this->createEnvelope();
            $env3 = $this->createEnvelope();

            (void) $mailbox->enqueue($env1);
            (void) $mailbox->enqueue($env2);
            (void) $mailbox->enqueue($env3);

            self::assertSame($env1, $mailbox->dequeue()->get());
            self::assertSame($env2, $mailbox->dequeue()->get());
            self::assertSame($env3, $mailbox->dequeue()->get());
        });
    }

    #[Test]
    public function dequeue_returns_none_when_empty(): void
    {
        run(static function (): void {
            $mailbox = new SwooleMailbox(MailboxConfig::unbounded(), ActorPath::root());

            $result = $mailbox->dequeue();
            self::assertTrue($result->isNone());
        });
    }

    #[Test]
    public function count_tracks_messages(): void
    {
        run(function (): void {
            $mailbox = new SwooleMailbox(MailboxConfig::unbounded(), ActorPath::root());

            self::assertSame(0, $mailbox->count());

            (void) $mailbox->enqueue($this->createEnvelope());
            self::assertSame(1, $mailbox->count());

            (void) $mailbox->enqueue($this->createEnvelope());
            self::assertSame(2, $mailbox->count());

            $mailbox->dequeue();
            self::assertSame(1, $mailbox->count());
        });
    }

    #[Test]
    public function is_empty_reflects_state(): void
    {
        run(function (): void {
            $mailbox = new SwooleMailbox(MailboxConfig::unbounded(), ActorPath::root());

            self::assertTrue($mailbox->isEmpty());

            (void) $mailbox->enqueue($this->createEnvelope());
            self::assertFalse($mailbox->isEmpty());

            $mailbox->dequeue();
            self::assertTrue($mailbox->isEmpty());
        });
    }

    #[Test]
    public function is_full_for_bounded_mailbox(): void
    {
        run(function (): void {
            $mailbox = new SwooleMailbox(
                MailboxConfig::bounded(2, OverflowStrategy::DropNewest),
                ActorPath::root(),
            );

            self::assertFalse($mailbox->isFull());

            (void) $mailbox->enqueue($this->createEnvelope());
            self::assertFalse($mailbox->isFull());

            (void) $mailbox->enqueue($this->createEnvelope());
            self::assertTrue($mailbox->isFull());

            $mailbox->dequeue();
            self::assertFalse($mailbox->isFull());
        });
    }

    #[Test]
    public function bounded_drop_newest_drops_incoming_when_full(): void
    {
        run(function (): void {
            $mailbox = new SwooleMailbox(
                MailboxConfig::bounded(2, OverflowStrategy::DropNewest),
                ActorPath::root(),
            );

            $env1 = $this->createEnvelope();
            $env2 = $this->createEnvelope();
            $env3 = $this->createEnvelope();

            self::assertSame(EnqueueResult::Accepted, $mailbox->enqueue($env1));
            self::assertSame(EnqueueResult::Accepted, $mailbox->enqueue($env2));
            self::assertSame(EnqueueResult::Dropped, $mailbox->enqueue($env3));

            self::assertSame(2, $mailbox->count());
            self::assertSame($env1, $mailbox->dequeue()->get());
            self::assertSame($env2, $mailbox->dequeue()->get());
        });
    }

    #[Test]
    public function bounded_drop_oldest_drops_oldest_when_full(): void
    {
        run(function (): void {
            $mailbox = new SwooleMailbox(
                MailboxConfig::bounded(2, OverflowStrategy::DropOldest),
                ActorPath::root(),
            );

            $env1 = $this->createEnvelope();
            $env2 = $this->createEnvelope();
            $env3 = $this->createEnvelope();

            self::assertSame(EnqueueResult::Accepted, $mailbox->enqueue($env1));
            self::assertSame(EnqueueResult::Accepted, $mailbox->enqueue($env2));
            self::assertSame(EnqueueResult::Accepted, $mailbox->enqueue($env3));

            self::assertSame(2, $mailbox->count());
            self::assertSame($env2, $mailbox->dequeue()->get());
            self::assertSame($env3, $mailbox->dequeue()->get());
        });
    }

    #[Test]
    public function bounded_throw_exception_throws_when_full(): void
    {
        $thrown = null;

        run(function () use (&$thrown): void {
            $mailbox = new SwooleMailbox(
                MailboxConfig::bounded(2, OverflowStrategy::ThrowException),
                ActorPath::root(),
            );

            (void) $mailbox->enqueue($this->createEnvelope());
            (void) $mailbox->enqueue($this->createEnvelope());

            try {
                (void) $mailbox->enqueue($this->createEnvelope());
            } catch (Throwable $e) {
                $thrown = $e;
            }
        });

        self::assertInstanceOf(MailboxOverflowException::class, $thrown);
    }

    #[Test]
    public function bounded_backpressure_returns_backpressured_when_full(): void
    {
        run(function (): void {
            $mailbox = new SwooleMailbox(
                MailboxConfig::bounded(2, OverflowStrategy::Backpressure),
                ActorPath::root(),
            );

            (void) $mailbox->enqueue($this->createEnvelope());
            (void) $mailbox->enqueue($this->createEnvelope());

            $result = $mailbox->enqueue($this->createEnvelope());
            self::assertSame(EnqueueResult::Backpressured, $result);
            self::assertSame(2, $mailbox->count());
        });
    }

    #[Test]
    public function close_prevents_enqueue(): void
    {
        $thrown = null;

        run(function () use (&$thrown): void {
            $mailbox = new SwooleMailbox(MailboxConfig::unbounded(), ActorPath::root());
            $mailbox->close();

            try {
                (void) $mailbox->enqueue($this->createEnvelope());
            } catch (Throwable $e) {
                $thrown = $e;
            }
        });

        self::assertInstanceOf(MailboxClosedException::class, $thrown);
    }

    #[Test]
    public function close_allows_dequeue_of_remaining_messages(): void
    {
        run(function (): void {
            $mailbox = new SwooleMailbox(MailboxConfig::unbounded(), ActorPath::root());

            $env = $this->createEnvelope();
            (void) $mailbox->enqueue($env);

            $mailbox->close();

            // Remaining messages can still be drained
            self::assertSame($env, $mailbox->dequeue()->get());
            self::assertTrue($mailbox->dequeue()->isNone());
        });
    }

    #[Test]
    public function dequeue_blocking_returns_immediately_when_message_available(): void
    {
        run(function (): void {
            $mailbox = new SwooleMailbox(MailboxConfig::unbounded(), ActorPath::root());

            $env = $this->createEnvelope();
            (void) $mailbox->enqueue($env);

            $result = $mailbox->dequeueBlocking(Duration::millis(100));
            self::assertSame($env, $result);
        });
    }

    #[Test]
    public function dequeue_blocking_throws_when_closed_and_empty(): void
    {
        $thrown = null;

        run(static function () use (&$thrown): void {
            $mailbox = new SwooleMailbox(MailboxConfig::unbounded(), ActorPath::root());
            $mailbox->close();

            try {
                $mailbox->dequeueBlocking(Duration::millis(100));
            } catch (Throwable $e) {
                $thrown = $e;
            }
        });

        self::assertInstanceOf(MailboxClosedException::class, $thrown);
    }

    #[Test]
    public function dequeue_blocking_waits_for_message(): void
    {
        run(function (): void {
            $mailbox = new SwooleMailbox(MailboxConfig::unbounded(), ActorPath::root());

            $env = $this->createEnvelope();

            // Spawn a coroutine that pushes a message after a short delay
            Coroutine::create(static function () use ($mailbox, $env): void {
                Coroutine::sleep(0.01); // 10ms delay
                (void) $mailbox->enqueue($env);
            });

            $result = $mailbox->dequeueBlocking(Duration::millis(500));
            self::assertSame($env, $result);
        });
    }

    #[Test]
    public function dequeue_blocking_throws_on_timeout(): void
    {
        $thrown = null;

        run(static function () use (&$thrown): void {
            $mailbox = new SwooleMailbox(MailboxConfig::unbounded(), ActorPath::root());

            try {
                $mailbox->dequeueBlocking(Duration::millis(10));
            } catch (Throwable $e) {
                $thrown = $e;
            }
        });

        self::assertInstanceOf(MailboxClosedException::class, $thrown);
    }

    private function createEnvelope(): Envelope
    {
        return Envelope::of(new stdClass(), ActorPath::root(), ActorPath::root());
    }
}
