<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Unit\Actor;

use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\DefaultTimerScheduler;
use Monadial\Nexus\Core\Actor\LocalActorRef;
use Monadial\Nexus\Core\Actor\TimerScheduler;
use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Core\Tests\Support\TestMailbox;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final readonly class TimerMessage
{
    public function __construct(public string $key) {}
}

/**
 * @psalm-suppress PropertyNotSetInConstructor
 */
#[CoversClass(DefaultTimerScheduler::class)]
final class TimerSchedulerTest extends TestCase
{
    private TestRuntime $runtime;
    private TestMailbox $mailbox;

    #[Test]
    public function start_single_timer_schedules_message(): void
    {
        $scheduler = $this->createScheduler();
        $scheduler->startSingleTimer('tick', new TimerMessage('tick'), Duration::seconds(1));

        self::assertTrue($scheduler->isTimerActive('tick'));

        $this->runtime->advanceTime(Duration::seconds(1));
        self::assertSame(1, $this->mailbox->count());
    }

    #[Test]
    public function start_single_timer_with_same_key_cancels_previous(): void
    {
        $scheduler = $this->createScheduler();
        $scheduler->startSingleTimer('tick', new TimerMessage('first'), Duration::seconds(1));
        $scheduler->startSingleTimer('tick', new TimerMessage('second'), Duration::seconds(2));

        self::assertTrue($scheduler->isTimerActive('tick'));

        $this->runtime->advanceTime(Duration::seconds(1));
        self::assertSame(0, $this->mailbox->count());

        $this->runtime->advanceTime(Duration::seconds(1));
        self::assertSame(1, $this->mailbox->count());
    }

    #[Test]
    public function cancel_stops_timer(): void
    {
        $scheduler = $this->createScheduler();
        $scheduler->startSingleTimer('tick', new TimerMessage('tick'), Duration::seconds(1));
        $scheduler->cancel('tick');

        self::assertFalse($scheduler->isTimerActive('tick'));

        $this->runtime->advanceTime(Duration::seconds(2));
        self::assertSame(0, $this->mailbox->count());
    }

    #[Test]
    public function cancel_all_stops_all_timers(): void
    {
        $scheduler = $this->createScheduler();
        $scheduler->startSingleTimer('a', new TimerMessage('a'), Duration::seconds(1));
        $scheduler->startSingleTimer('b', new TimerMessage('b'), Duration::seconds(1));
        $scheduler->startTimerWithFixedDelay('c', new TimerMessage('c'), Duration::seconds(1));

        $scheduler->cancelAll();

        self::assertFalse($scheduler->isTimerActive('a'));
        self::assertFalse($scheduler->isTimerActive('b'));
        self::assertFalse($scheduler->isTimerActive('c'));

        $this->runtime->advanceTime(Duration::seconds(2));
        self::assertSame(0, $this->mailbox->count());
    }

    #[Test]
    public function is_timer_active_returns_false_for_unknown_key(): void
    {
        $scheduler = $this->createScheduler();
        self::assertFalse($scheduler->isTimerActive('nonexistent'));
    }

    #[Test]
    public function start_timer_with_fixed_delay_repeats(): void
    {
        $scheduler = $this->createScheduler();
        $scheduler->startTimerWithFixedDelay('repeat', new TimerMessage('repeat'), Duration::seconds(1));

        $this->runtime->advanceTime(Duration::seconds(1));
        self::assertSame(1, $this->mailbox->count());

        $this->runtime->advanceTime(Duration::seconds(1));
        self::assertSame(2, $this->mailbox->count());

        $this->runtime->advanceTime(Duration::seconds(1));
        self::assertSame(3, $this->mailbox->count());
    }

    #[Test]
    public function start_timer_at_fixed_rate_repeats(): void
    {
        $scheduler = $this->createScheduler();
        $scheduler->startTimerAtFixedRate('rate', new TimerMessage('rate'), Duration::seconds(1));

        $this->runtime->advanceTime(Duration::seconds(1));
        self::assertSame(1, $this->mailbox->count());

        $this->runtime->advanceTime(Duration::seconds(1));
        self::assertSame(2, $this->mailbox->count());
    }

    #[Test]
    public function start_timer_with_fixed_delay_with_initial_delay(): void
    {
        $scheduler = $this->createScheduler();
        $scheduler->startTimerWithFixedDelay(
            'delayed',
            new TimerMessage('delayed'),
            Duration::seconds(1),
            Duration::seconds(5),
        );

        $this->runtime->advanceTime(Duration::seconds(1));
        self::assertSame(0, $this->mailbox->count());

        $this->runtime->advanceTime(Duration::seconds(4));
        self::assertSame(1, $this->mailbox->count());

        $this->runtime->advanceTime(Duration::seconds(1));
        self::assertSame(2, $this->mailbox->count());
    }

    #[Test]
    public function cancel_nonexistent_key_is_noop(): void
    {
        $scheduler = $this->createScheduler();
        $scheduler->cancel('nonexistent');
        self::assertFalse($scheduler->isTimerActive('nonexistent'));
    }

    #[Override]
    protected function setUp(): void
    {
        $this->runtime = new TestRuntime();
        $this->mailbox = TestMailbox::unbounded();
    }

    private function createScheduler(): TimerScheduler
    {
        $selfRef = new LocalActorRef(
            ActorPath::fromString('/user/test'),
            $this->mailbox,
            static fn(): bool => true,
            $this->runtime,
        );

        return new DefaultTimerScheduler($selfRef, $this->runtime);
    }
}
