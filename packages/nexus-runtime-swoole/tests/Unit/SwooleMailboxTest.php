<?php

declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Swoole\Tests\Unit;

use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Exception\MailboxClosedException;
use Monadial\Nexus\Runtime\Exception\MailboxOverflowException;
use Monadial\Nexus\Runtime\Exception\MailboxTimeoutException;
use Monadial\Nexus\Runtime\Mailbox\EnqueueResult;
use Monadial\Nexus\Runtime\Mailbox\Mailbox;
use Monadial\Nexus\Runtime\Mailbox\MailboxConfig;
use Monadial\Nexus\Runtime\Mailbox\OverflowStrategy;
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
            $mailbox = new SwooleMailbox(MailboxConfig::unbounded());
            self::assertInstanceOf(Mailbox::class, $mailbox);
        });
    }

    #[Test]
    public function enqueue_dequeue_fifo_order(): void
    {
        run(function (): void {
            $mailbox = new SwooleMailbox(MailboxConfig::unbounded());

            $env1 = $this->createMessage();
            $env2 = $this->createMessage();
            $env3 = $this->createMessage();

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
            $mailbox = new SwooleMailbox(MailboxConfig::unbounded());

            $result = $mailbox->dequeue();
            self::assertTrue($result->isNone());
        });
    }

    #[Test]
    public function count_tracks_messages(): void
    {
        run(function (): void {
            $mailbox = new SwooleMailbox(MailboxConfig::unbounded());

            self::assertSame(0, $mailbox->count());

            (void) $mailbox->enqueue($this->createMessage());
            self::assertSame(1, $mailbox->count());

            (void) $mailbox->enqueue($this->createMessage());
            self::assertSame(2, $mailbox->count());

            $mailbox->dequeue();
            self::assertSame(1, $mailbox->count());
        });
    }

    #[Test]
    public function is_empty_reflects_state(): void
    {
        run(function (): void {
            $mailbox = new SwooleMailbox(MailboxConfig::unbounded());

            self::assertTrue($mailbox->isEmpty());

            (void) $mailbox->enqueue($this->createMessage());
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
            );

            self::assertFalse($mailbox->isFull());

            (void) $mailbox->enqueue($this->createMessage());
            self::assertFalse($mailbox->isFull());

            (void) $mailbox->enqueue($this->createMessage());
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
            );

            $env1 = $this->createMessage();
            $env2 = $this->createMessage();
            $env3 = $this->createMessage();

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
            );

            $env1 = $this->createMessage();
            $env2 = $this->createMessage();
            $env3 = $this->createMessage();

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
            );

            (void) $mailbox->enqueue($this->createMessage());
            (void) $mailbox->enqueue($this->createMessage());

            try {
                (void) $mailbox->enqueue($this->createMessage());
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
            );

            (void) $mailbox->enqueue($this->createMessage());
            (void) $mailbox->enqueue($this->createMessage());

            $result = $mailbox->enqueue($this->createMessage());
            self::assertSame(EnqueueResult::Backpressured, $result);
            self::assertSame(2, $mailbox->count());
        });
    }

    #[Test]
    public function close_prevents_enqueue(): void
    {
        $thrown = null;

        run(function () use (&$thrown): void {
            $mailbox = new SwooleMailbox(MailboxConfig::unbounded());
            $mailbox->close();

            try {
                (void) $mailbox->enqueue($this->createMessage());
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
            $mailbox = new SwooleMailbox(MailboxConfig::unbounded());

            $env = $this->createMessage();
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
            $mailbox = new SwooleMailbox(MailboxConfig::unbounded());

            $env = $this->createMessage();
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
            $mailbox = new SwooleMailbox(MailboxConfig::unbounded());
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
            $mailbox = new SwooleMailbox(MailboxConfig::unbounded());

            $env = $this->createMessage();

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
    public function dequeue_blocking_throws_timeout_exception_on_timeout(): void
    {
        $thrown = null;

        run(static function () use (&$thrown): void {
            $mailbox = new SwooleMailbox(MailboxConfig::unbounded());

            try {
                $mailbox->dequeueBlocking(Duration::millis(10));
            } catch (Throwable $e) {
                $thrown = $e;
            }
        });

        self::assertInstanceOf(MailboxTimeoutException::class, $thrown);
    }

    #[Test]
    public function dequeue_blocking_throws_closed_exception_when_closed(): void
    {
        $thrown = null;

        run(static function () use (&$thrown): void {
            $mailbox = new SwooleMailbox(MailboxConfig::unbounded());
            $mailbox->close();

            try {
                $mailbox->dequeueBlocking(Duration::millis(10));
            } catch (Throwable $e) {
                $thrown = $e;
            }
        });

        self::assertInstanceOf(MailboxClosedException::class, $thrown);
    }

    private function createMessage(): object
    {
        return new stdClass();
    }
}
