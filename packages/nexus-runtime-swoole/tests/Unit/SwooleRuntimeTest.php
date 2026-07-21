<?php

declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Swoole\Tests\Unit;

use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Exception\FutureCancelledException;
use Monadial\Nexus\Runtime\Mailbox\MailboxConfig;
use Monadial\Nexus\Runtime\Runtime\Cancellable;
use Monadial\Nexus\Runtime\Runtime\Runtime;
use Monadial\Nexus\Runtime\Swoole\SwooleMailbox;
use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SwooleRuntime::class)]
final class SwooleRuntimeTest extends TestCase
{
    #[Test]
    public function it_implements_runtime(): void
    {
        $runtime = new SwooleRuntime();
        self::assertInstanceOf(Runtime::class, $runtime);
    }

    #[Test]
    public function name_returns_swoole(): void
    {
        $runtime = new SwooleRuntime();
        self::assertSame('swoole', $runtime->name());
    }

    #[Test]
    public function create_mailbox_returns_swoole_mailbox(): void
    {
        $runtime = new SwooleRuntime();

        // SwooleMailbox requires coroutine context (Channel creation)
        // so we test it inside run()
        $mailbox = null;

        $runtime->spawn(static function () use ($runtime, &$mailbox): void {
            $mailbox = $runtime->createMailbox(MailboxConfig::unbounded());
        });

        $runtime->scheduleOnce(Duration::millis(50), static function () use ($runtime): void {
            $runtime->shutdown(Duration::millis(100));
        });

        $runtime->run();

        self::assertInstanceOf(SwooleMailbox::class, $mailbox);
    }

    #[Test]
    public function defer_runs_the_task(): void
    {
        $runtime = new SwooleRuntime();
        $value = 0;

        $runtime->defer(static function () use (&$value): void {
            $value = 9;
        });

        $runtime->scheduleOnce(Duration::millis(50), static function () use ($runtime): void {
            $runtime->shutdown(Duration::millis(100));
        });

        $runtime->run();

        self::assertSame(9, $value);
    }

    #[Test]
    public function spawn_returns_task_id(): void
    {
        $runtime = new SwooleRuntime();
        $id = $runtime->spawn(static function (): void {});
        self::assertNotEmpty($id);
        self::assertMatchesRegularExpression('/^swoole-\d+$/', $id);
    }

    #[Test]
    public function spawn_returns_unique_ids(): void
    {
        $runtime = new SwooleRuntime();
        $id1 = $runtime->spawn(static function (): void {});
        $id2 = $runtime->spawn(static function (): void {});
        self::assertNotSame($id1, $id2);
    }

    #[Test]
    public function is_running_reflects_state(): void
    {
        $runtime = new SwooleRuntime();
        self::assertFalse($runtime->isRunning());
    }

    #[Test]
    public function run_and_shutdown_lifecycle(): void
    {
        $runtime = new SwooleRuntime();
        $executed = false;

        $runtime->spawn(static function () use (&$executed): void {
            $executed = true;
        });

        $runtime->scheduleOnce(Duration::millis(50), static function () use ($runtime): void {
            $runtime->shutdown(Duration::millis(100));
        });

        $runtime->run();

        self::assertTrue($executed);
        self::assertFalse($runtime->isRunning());
    }

    #[Test]
    public function spawn_executes_actor_loop(): void
    {
        $runtime = new SwooleRuntime();
        $value = 0;

        $runtime->spawn(static function () use (&$value): void {
            $value = 42;
        });

        $runtime->scheduleOnce(Duration::millis(50), static function () use ($runtime): void {
            $runtime->shutdown(Duration::millis(100));
        });

        $runtime->run();

        self::assertSame(42, $value);
    }

    #[Test]
    public function schedule_once_returns_cancellable(): void
    {
        $runtime = new SwooleRuntime();
        $cancellable = $runtime->scheduleOnce(Duration::seconds(1), static function (): void {});
        self::assertInstanceOf(Cancellable::class, $cancellable);
        self::assertFalse($cancellable->isCancelled());
    }

    #[Test]
    public function schedule_repeatedly_returns_cancellable(): void
    {
        $runtime = new SwooleRuntime();
        $cancellable = $runtime->scheduleRepeatedly(
            Duration::seconds(1),
            Duration::seconds(2),
            static function (): void {},
        );
        self::assertInstanceOf(Cancellable::class, $cancellable);
        self::assertFalse($cancellable->isCancelled());
    }

    #[Test]
    public function schedule_once_fires_during_run(): void
    {
        $runtime = new SwooleRuntime();
        $fired = false;

        $runtime->scheduleOnce(Duration::millis(1), static function () use (&$fired): void {
            $fired = true;
        });

        $runtime->scheduleOnce(Duration::millis(100), static function () use ($runtime): void {
            $runtime->shutdown(Duration::millis(100));
        });

        $runtime->run();

        self::assertTrue($fired);
    }

    #[Test]
    public function schedule_repeatedly_fires_during_run(): void
    {
        $runtime = new SwooleRuntime();
        $count = 0;

        $cancellable = $runtime->scheduleRepeatedly(
            Duration::millis(1),
            Duration::millis(10),
            static function () use (&$count): void {
                $count++;
            },
        );

        $runtime->scheduleOnce(Duration::millis(100), static function () use ($runtime, $cancellable): void {
            $cancellable->cancel();
            $runtime->shutdown(Duration::millis(100));
        });

        $runtime->run();

        self::assertGreaterThan(0, $count);
    }

    // ========================================================================
    // Repeating-timer cancellation is linked to the live tick (REL-005)
    // ========================================================================

    #[Test]
    public function cancel_before_first_fire_prevents_all_invocations(): void
    {
        $runtime = new SwooleRuntime();
        $count = 0;

        $cancellable = $runtime->scheduleRepeatedly(
            Duration::millis(20),
            Duration::millis(10),
            static function () use (&$count): void {
                $count++;
            },
        );

        $cancellable->cancel();

        $runtime->scheduleOnce(Duration::millis(80), static function () use ($runtime): void {
            $runtime->shutdown(Duration::millis(100));
        });

        $runtime->run();

        self::assertSame(0, $count);
    }

    #[Test]
    public function cancel_after_first_fire_stops_the_repeating_tick(): void
    {
        $runtime = new SwooleRuntime();
        $count = 0;
        $countAtCancel = -1;
        $countAfterGrace = -1;

        $cancellable = $runtime->scheduleRepeatedly(
            Duration::millis(1),
            Duration::millis(10),
            static function () use (&$count): void {
                $count++;
            },
        );

        // Cancel well after the first fire, while the runtime keeps running.
        $runtime->scheduleOnce(
            Duration::millis(35),
            static function () use ($cancellable, &$count, &$countAtCancel): void {
                $countAtCancel = $count;
                $cancellable->cancel();
            },
        );

        // Give a cancelled-but-leaked tick ample time to expose itself
        // before stopping the runtime (cancellation must not depend on
        // the shutdown timer sweep).
        $runtime->scheduleOnce(
            Duration::millis(150),
            static function () use ($runtime, &$count, &$countAfterGrace): void {
                $countAfterGrace = $count;
                $runtime->shutdown(Duration::millis(100));
            },
        );

        $runtime->run();

        self::assertGreaterThan(0, $countAtCancel);
        self::assertSame($countAtCancel, $countAfterGrace);
    }

    #[Test]
    public function restarted_owner_schedule_replaces_cancelled_tick(): void
    {
        $runtime = new SwooleRuntime();
        $oldTicks = 0;
        $newTicks = 0;
        $oldTicksAtCancel = -1;
        $oldTicksAtEnd = -1;

        $old = $runtime->scheduleRepeatedly(
            Duration::millis(1),
            Duration::millis(10),
            static function () use (&$oldTicks): void {
                $oldTicks++;
            },
        );

        // Simulate an owner restart: cancel the old incarnation's timer
        // after it started ticking, then schedule the replacement.
        $runtime->scheduleOnce(
            Duration::millis(35),
            static function () use ($runtime, $old, &$oldTicks, &$oldTicksAtCancel, &$newTicks): void {
                $oldTicksAtCancel = $oldTicks;
                $old->cancel();

                $_ = $runtime->scheduleRepeatedly(
                    Duration::millis(1),
                    Duration::millis(10),
                    static function () use (&$newTicks): void {
                        $newTicks++;
                    },
                );
            },
        );

        $runtime->scheduleOnce(
            Duration::millis(150),
            static function () use ($runtime, &$oldTicks, &$oldTicksAtEnd): void {
                $oldTicksAtEnd = $oldTicks;
                $runtime->shutdown(Duration::millis(100));
            },
        );

        $runtime->run();

        self::assertGreaterThan(0, $oldTicksAtCancel);
        // The old incarnation's tick must not fire after cancel...
        self::assertSame($oldTicksAtCancel, $oldTicksAtEnd);
        // ...while the replacement ticks freely.
        self::assertGreaterThan(0, $newTicks);
    }

    #[Test]
    public function multiple_coroutines_execute(): void
    {
        $runtime = new SwooleRuntime();
        /** @var list<string> $order */
        $order = [];

        $runtime->spawn(static function () use (&$order): void {
            $order[] = 'a';
        });

        $runtime->spawn(static function () use (&$order): void {
            $order[] = 'b';
        });

        $runtime->scheduleOnce(Duration::millis(50), static function () use ($runtime): void {
            $runtime->shutdown(Duration::millis(100));
        });

        $runtime->run();

        self::assertContains('a', $order);
        self::assertContains('b', $order);
        self::assertCount(2, $order);
    }

    #[Test]
    public function cancelled_future_slot_await_throws_cancelled_exception(): void
    {
        $runtime = new SwooleRuntime();
        $thrown = null;

        $runtime->spawn(static function () use ($runtime, &$thrown): void {
            $slot = $runtime->createFutureSlot();
            $slot->cancel();

            try {
                $slot->await();
            } catch (FutureCancelledException $e) {
                $thrown = $e;
            }
        });

        $runtime->scheduleOnce(Duration::millis(50), static function () use ($runtime): void {
            $runtime->shutdown(Duration::millis(100));
        });

        $runtime->run();

        self::assertInstanceOf(FutureCancelledException::class, $thrown);
    }
}
