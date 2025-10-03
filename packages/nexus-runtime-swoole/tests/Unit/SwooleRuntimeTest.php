<?php
declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Swoole\Tests\Unit;

use Monadial\Nexus\Core\Actor\Cancellable;
use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Core\Mailbox\MailboxConfig;
use Monadial\Nexus\Core\Runtime\Runtime;
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
}
