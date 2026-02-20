<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Unit\Support;

use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Core\Mailbox\MailboxConfig;
use Monadial\Nexus\Core\Tests\Support\TestClock;
use Monadial\Nexus\Core\Tests\Support\TestMailbox;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class TestRuntimeTest extends TestCase
{
    #[Test]
    public function nameReturnsTest(): void
    {
        $runtime = new TestRuntime();

        self::assertSame('test', $runtime->name());
    }

    #[Test]
    public function createMailboxReturnsTestMailbox(): void
    {
        $runtime = new TestRuntime();

        $mailbox = $runtime->createMailbox(MailboxConfig::unbounded());

        self::assertInstanceOf(TestMailbox::class, $mailbox);
    }

    #[Test]
    public function spawnTracksCallablesAndReturnsIds(): void
    {
        $runtime = new TestRuntime();

        $id1 = $runtime->spawn(static function (): void {});
        $id2 = $runtime->spawn(static function (): void {});

        self::assertSame('test-actor-0', $id1);
        self::assertSame('test-actor-1', $id2);
        self::assertCount(2, $runtime->spawnedActors());
    }

    #[Test]
    public function runAndShutdownToggleIsRunning(): void
    {
        $runtime = new TestRuntime();

        self::assertFalse($runtime->isRunning());

        $runtime->run();
        self::assertTrue($runtime->isRunning());

        $runtime->shutdown(Duration::seconds(5));
        self::assertFalse($runtime->isRunning());
    }

    #[Test]
    public function clockReturnsSameClock(): void
    {
        $clock = new TestClock();
        $runtime = new TestRuntime($clock);

        self::assertSame($clock, $runtime->clock());
    }

    #[Test]
    public function sleepAdvancesClock(): void
    {
        $runtime = new TestRuntime();
        $before = $runtime->clock()->now();

        $runtime->sleep(Duration::seconds(10));

        $after = $runtime->clock()->now();
        $diff = $after->getTimestamp() - $before->getTimestamp();

        self::assertSame(10, $diff);
    }

    #[Test]
    public function scheduleOnceFiresAfterAdvance(): void
    {
        $runtime = new TestRuntime();
        $fired = false;

        $runtime->scheduleOnce(Duration::seconds(5), static function () use (&$fired): void {
            $fired = true;
        });

        // Not enough time yet
        $runtime->advanceTime(Duration::seconds(3));
        self::assertFalse($fired);

        // Now enough time
        $runtime->advanceTime(Duration::seconds(3));
        self::assertTrue($fired);
    }

    #[Test]
    public function scheduleOnceDoesNotFireIfCancelled(): void
    {
        $runtime = new TestRuntime();
        $fired = false;

        $cancellable = $runtime->scheduleOnce(Duration::seconds(5), static function () use (&$fired): void {
            $fired = true;
        });

        $cancellable->cancel();

        $runtime->advanceTime(Duration::seconds(10));
        self::assertFalse($fired);
    }

    #[Test]
    public function scheduleRepeatedlyFiresMultipleTimes(): void
    {
        $runtime = new TestRuntime();
        $count = 0;

        $runtime->scheduleRepeatedly(
            Duration::seconds(2),
            Duration::seconds(3),
            static function () use (&$count): void {
                $count++;
            },
        );

        // Advance past initial delay (2s)
        $runtime->advanceTime(Duration::seconds(2));
        self::assertSame(1, $count);

        // Advance past first interval (2+3=5s)
        $runtime->advanceTime(Duration::seconds(3));
        self::assertSame(2, $count);

        // Advance past second interval (5+3=8s)
        $runtime->advanceTime(Duration::seconds(3));
        self::assertSame(3, $count);
    }

    #[Test]
    public function scheduleRepeatedlyStopsWhenCancelled(): void
    {
        $runtime = new TestRuntime();
        $count = 0;

        $cancellable = $runtime->scheduleRepeatedly(
            Duration::seconds(1),
            Duration::seconds(1),
            static function () use (&$count): void {
                $count++;
            },
        );

        $runtime->advanceTime(Duration::seconds(1));
        self::assertSame(1, $count);

        $cancellable->cancel();

        $runtime->advanceTime(Duration::seconds(10));
        self::assertSame(1, $count);
    }

    #[Test]
    public function scheduleOnceFiresOnlyOnce(): void
    {
        $runtime = new TestRuntime();
        $count = 0;

        $runtime->scheduleOnce(Duration::seconds(1), static function () use (&$count): void {
            $count++;
        });

        $runtime->advanceTime(Duration::seconds(1));
        self::assertSame(1, $count);

        $runtime->advanceTime(Duration::seconds(10));
        self::assertSame(1, $count);
    }

    #[Test]
    public function yieldIsNoOp(): void
    {
        $runtime = new TestRuntime();

        // Should not throw
        $runtime->yield();

        self::assertFalse($runtime->isRunning());
    }

    #[Test]
    public function multipleTimersFireIndependently(): void
    {
        $runtime = new TestRuntime();
        $fired1 = false;
        $fired2 = false;

        $runtime->scheduleOnce(Duration::seconds(3), static function () use (&$fired1): void {
            $fired1 = true;
        });

        $runtime->scheduleOnce(Duration::seconds(5), static function () use (&$fired2): void {
            $fired2 = true;
        });

        $runtime->advanceTime(Duration::seconds(4));
        self::assertTrue($fired1);
        self::assertFalse($fired2);

        $runtime->advanceTime(Duration::seconds(2));
        self::assertTrue($fired2);
    }
}
