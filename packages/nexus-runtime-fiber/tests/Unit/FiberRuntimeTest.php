<?php
declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Fiber\Tests\Unit;

use Monadial\Nexus\Core\Actor\Cancellable;
use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Core\Mailbox\MailboxConfig;
use Monadial\Nexus\Core\Runtime\Runtime;
use Monadial\Nexus\Runtime\Fiber\FiberMailbox;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(FiberRuntime::class)]
final class FiberRuntimeTest extends TestCase
{
    #[Test]
    public function it_implements_runtime(): void
    {
        $runtime = new FiberRuntime();
        self::assertInstanceOf(Runtime::class, $runtime);
    }

    #[Test]
    public function name_returns_fiber(): void
    {
        $runtime = new FiberRuntime();
        self::assertSame('fiber', $runtime->name());
    }

    #[Test]
    public function create_mailbox_returns_fiber_mailbox(): void
    {
        $runtime = new FiberRuntime();
        $mailbox = $runtime->createMailbox(MailboxConfig::unbounded());
        self::assertInstanceOf(FiberMailbox::class, $mailbox);
    }

    #[Test]
    public function spawn_returns_task_id(): void
    {
        $runtime = new FiberRuntime();
        $id = $runtime->spawn(static function (): void {
        });
        self::assertNotEmpty($id);
        self::assertMatchesRegularExpression('/^fiber-\d+$/', $id);
    }

    #[Test]
    public function spawn_returns_unique_ids(): void
    {
        $runtime = new FiberRuntime();
        $id1 = $runtime->spawn(static function (): void {
        });
        $id2 = $runtime->spawn(static function (): void {
        });
        self::assertNotSame($id1, $id2);
    }

    #[Test]
    public function is_running_reflects_state(): void
    {
        $runtime = new FiberRuntime();
        self::assertFalse($runtime->isRunning());
    }

    #[Test]
    public function run_and_shutdown_lifecycle(): void
    {
        $runtime = new FiberRuntime();
        $executed = false;

        $runtime->spawn(static function () use (&$executed): void {
            $executed = true;
        });

        // Schedule shutdown after a tick
        $runtime->scheduleOnce(Duration::millis(1), static function () use ($runtime): void {
            $runtime->shutdown(Duration::millis(100));
        });

        $runtime->run();

        self::assertTrue($executed);
        self::assertFalse($runtime->isRunning());
    }

    #[Test]
    public function spawn_executes_actor_loop(): void
    {
        $runtime = new FiberRuntime();
        $value = 0;

        $runtime->spawn(static function () use (&$value): void {
            $value = 42;
        });

        $runtime->scheduleOnce(Duration::millis(1), static function () use ($runtime): void {
            $runtime->shutdown(Duration::millis(100));
        });

        $runtime->run();

        self::assertSame(42, $value);
    }

    #[Test]
    public function schedule_once_delegates_to_scheduler(): void
    {
        $runtime = new FiberRuntime();
        $cancellable = $runtime->scheduleOnce(Duration::seconds(1), static function (): void {
        });
        self::assertInstanceOf(Cancellable::class, $cancellable);
        self::assertFalse($cancellable->isCancelled());
    }

    #[Test]
    public function schedule_repeatedly_delegates_to_scheduler(): void
    {
        $runtime = new FiberRuntime();
        $cancellable = $runtime->scheduleRepeatedly(
            Duration::seconds(1),
            Duration::seconds(2),
            static function (): void {
            },
        );
        self::assertInstanceOf(Cancellable::class, $cancellable);
        self::assertFalse($cancellable->isCancelled());
    }

    #[Test]
    public function schedule_once_fires_during_run(): void
    {
        $runtime = new FiberRuntime();
        $fired = false;

        $runtime->scheduleOnce(Duration::millis(1), static function () use (&$fired): void {
            $fired = true;
        });

        $runtime->scheduleOnce(Duration::millis(10), static function () use ($runtime): void {
            $runtime->shutdown(Duration::millis(100));
        });

        $runtime->run();

        self::assertTrue($fired);
    }

    #[Test]
    public function schedule_repeatedly_fires_during_run(): void
    {
        $runtime = new FiberRuntime();
        $count = 0;

        $cancellable = $runtime->scheduleRepeatedly(
            Duration::millis(1),
            Duration::millis(1),
            static function () use (&$count): void {
                $count++;
            },
        );

        $runtime->scheduleOnce(Duration::millis(50), static function () use ($runtime, $cancellable): void {
            $cancellable->cancel();
            $runtime->shutdown(Duration::millis(100));
        });

        $runtime->run();

        self::assertGreaterThan(0, $count);
    }

    #[Test]
    public function multiple_fibers_execute(): void
    {
        $runtime = new FiberRuntime();
        /** @var list<string> $order */
        $order = [];

        $runtime->spawn(static function () use (&$order): void {
            $order[] = 'a';
        });

        $runtime->spawn(static function () use (&$order): void {
            $order[] = 'b';
        });

        $runtime->scheduleOnce(Duration::millis(1), static function () use ($runtime): void {
            $runtime->shutdown(Duration::millis(100));
        });

        $runtime->run();

        self::assertContains('a', $order);
        self::assertContains('b', $order);
        self::assertCount(2, $order);
    }
}
