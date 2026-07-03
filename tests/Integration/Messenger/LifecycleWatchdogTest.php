<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Messenger;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Tests\Support\TestClock;
use Monadial\Nexus\Messenger\Lifecycle\LifecycleThresholds;
use Monadial\Nexus\Messenger\Lifecycle\LifecycleWatchdog;
use Monadial\Nexus\Messenger\Lifecycle\MessagesProcessed;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(LifecycleWatchdog::class)]
final class LifecycleWatchdogTest extends TestCase
{
    #[Test]
    public function shutsTheSystemDownWhenTheMessageLimitIsReached(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('watchdog-messages', $runtime);
        $safetyTriggered = false;

        $watchdog = $system->spawn(Props::fromBehavior(LifecycleWatchdog::create(
            $system,
            LifecycleThresholds::none()->withMessageLimit(3),
            Duration::millis(30),
            Duration::seconds(1),
        )), 'watchdog');

        $watchdog->tell(new MessagesProcessed(2));
        $watchdog->tell(new MessagesProcessed(1));

        $runtime->scheduleOnce(Duration::seconds(3), static function () use ($system, &$safetyTriggered): void {
            $safetyTriggered = true;
            $system->shutdown(Duration::seconds(1));
        });
        $system->run();

        self::assertFalse($safetyTriggered, 'watchdog should have shut the system down before the safety net');
    }

    #[Test]
    public function shutsTheSystemDownWhenTheMemoryBudgetIsExceeded(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('watchdog-memory', $runtime);
        $safetyTriggered = false;

        $system->spawn(Props::fromBehavior(LifecycleWatchdog::create(
            $system,
            LifecycleThresholds::none()->withMemoryLimit(1024),
            Duration::millis(30),
            Duration::seconds(1),
            static fn(): int => 2048,
        )), 'watchdog');

        $runtime->scheduleOnce(Duration::seconds(3), static function () use ($system, &$safetyTriggered): void {
            $safetyTriggered = true;
            $system->shutdown(Duration::seconds(1));
        });
        $system->run();

        self::assertFalse($safetyTriggered, 'watchdog should have shut the system down before the safety net');
    }

    #[Test]
    public function shutsTheSystemDownWhenTheTimeLimitIsReached(): void
    {
        $runtime = new FiberRuntime();
        $clock = new TestClock();
        $system = ActorSystem::create('watchdog-time', $runtime, clock: $clock);
        $safetyTriggered = false;

        $system->spawn(Props::fromBehavior(LifecycleWatchdog::create(
            $system,
            LifecycleThresholds::none()->withTimeLimit(Duration::seconds(60)),
            Duration::millis(30),
            Duration::seconds(1),
        )), 'watchdog');

        // Advance the injected clock past the 60 s time limit so the next Tick sees a breach.
        $runtime->scheduleOnce(Duration::millis(100), static function () use ($clock): void {
            $clock->advance(Duration::seconds(61));
        });

        $runtime->scheduleOnce(Duration::seconds(3), static function () use ($system, &$safetyTriggered): void {
            $safetyTriggered = true;
            $system->shutdown(Duration::seconds(1));
        });
        $system->run();

        self::assertFalse($safetyTriggered, 'watchdog should have shut the system down before the safety net');
    }
}
