<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Fiber;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Actor\StashBuffer;
use Monadial\Nexus\Core\Actor\TimerScheduler;
use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Core\Mailbox\Envelope;
use Monadial\Nexus\Core\Supervision\SupervisionStrategy;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use Monadial\Nexus\Tests\Integration\Fiber\Messages\Initialize;
use Monadial\Nexus\Tests\Integration\Fiber\Messages\Tick;
use Monadial\Nexus\Tests\Integration\Fiber\Messages\WorkItem;
use PHPUnit\Framework\TestCase;

final class ComposableBehaviorTest extends TestCase
{
    /**
     * Test: withTimers schedules repeating messages to the actor.
     *
     * Flow:
     *   1. Actor wraps behavior with withTimers, starting a repeating tick every 50ms
     *   2. Inner receive handler counts Tick messages
     *   3. After 300ms, shutdown and verify multiple ticks were received
     *
     * @psalm-suppress InvalidArgument Behavior::receive() template inference
     */
    public function testWithTimersSchedulesMessages(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('timer-test', $runtime);

        $tickCount = 0;

        $behavior = Behavior::withTimers(
            static function (TimerScheduler $timers) use (&$tickCount): Behavior {
                $timers->startTimerAtFixedRate('tick', new Tick(), Duration::millis(50));

                return Behavior::receive(
                    static function (ActorContext $ctx, object $msg) use (&$tickCount): Behavior {
                        if ($msg instanceof Tick) {
                            $tickCount++;
                        }

                        return Behavior::same();
                    },
                );
            },
        );

        $system->spawn(Props::fromBehavior($behavior), 'ticker');

        $runtime->scheduleOnce(Duration::millis(300), static function () use ($system): void {
            $system->shutdown(Duration::seconds(1));
        });

        $system->run();

        // With 50ms interval and 300ms runtime, expect at least 3 ticks
        self::assertGreaterThanOrEqual(3, $tickCount, 'Expected at least 3 ticks in 300ms with 50ms interval');
    }

    /**
     * Test: withStash provides a StashBuffer for inline replay.
     *
     * Flow:
     *   1. Actor uses withStash(100) to get a StashBuffer
     *   2. Initial behavior stashes WorkItem messages until Initialize arrives
     *   3. On Initialize, unstashAll replays stashed messages through ready behavior
     *   4. Verify all 3 WorkItems are processed in order [1, 2, 3]
     *
     * @psalm-suppress InvalidArgument Behavior::receive() template inference
     */
    public function testWithStashInlineReplay(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('stash-composable-test', $runtime);

        /** @var list<int> $processedIds */
        $processedIds = [];

        $behavior = Behavior::withStash(
            100,
            static function (StashBuffer $stash) use (&$processedIds): Behavior {
                $readyBehavior = Behavior::receive(
                    static function (ActorContext $ctx, object $msg) use (&$processedIds): Behavior {
                        if ($msg instanceof WorkItem) {
                            $processedIds[] = $msg->id;
                        }

                        return Behavior::same();
                    },
                );

                return Behavior::receive(
                    static function (ActorContext $ctx, object $msg) use ($stash, $readyBehavior): Behavior {
                        if ($msg instanceof Initialize) {
                            return $stash->unstashAll($readyBehavior);
                        }

                        // Stash everything else
                        $stash->stash(Envelope::of($msg, ActorPath::root(), $ctx->path()));

                        return Behavior::same();
                    },
                );
            },
        );

        $ref = $system->spawn(Props::fromBehavior($behavior), 'stasher');

        // Send work items BEFORE the Initialize message
        $ref->tell(new WorkItem(1));
        $ref->tell(new WorkItem(2));
        $ref->tell(new WorkItem(3));

        // Now initialize: unstashAll should replay items inline
        $ref->tell(new Initialize());

        $runtime->scheduleOnce(Duration::millis(200), static function () use ($system): void {
            $system->shutdown(Duration::seconds(1));
        });

        $system->run();

        // All 3 work items should have been replayed inline in order
        self::assertSame([1, 2, 3], $processedIds);
    }

    /**
     * Test: Full composition chain -- setup -> withTimers -> supervise -> receive.
     *
     * Flow:
     *   1. Outer setup provides initialization context
     *   2. withTimers adds timer capability (not actively used here)
     *   3. supervise wraps the inner behavior with one-for-one supervision
     *   4. Inner receive handler processes WorkItem messages
     *   5. Send 2 WorkItems, verify both are processed
     *
     * @psalm-suppress InvalidArgument Behavior::receive() template inference
     * @psalm-suppress UnusedClosureParam setup/withTimers closures require $ctx/$timers params
     */
    public function testFullComposition(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('composition-test', $runtime);

        /** @var list<int> $processedIds */
        $processedIds = [];

        $behavior = Behavior::setup(
            static function (ActorContext $ctx) use (&$processedIds): Behavior {
                return Behavior::withTimers(
                    static function (TimerScheduler $timers) use (&$processedIds): Behavior {
                        $innerBehavior = Behavior::receive(
                            static function (ActorContext $ctx, object $msg) use (&$processedIds): Behavior {
                                if ($msg instanceof WorkItem) {
                                    $processedIds[] = $msg->id;
                                }

                                return Behavior::same();
                            },
                        );

                        return Behavior::supervise(
                            $innerBehavior,
                            SupervisionStrategy::oneForOne(maxRetries: 3),
                        );
                    },
                );
            },
        );

        $ref = $system->spawn(Props::fromBehavior($behavior), 'composed');

        $ref->tell(new WorkItem(42));
        $ref->tell(new WorkItem(99));

        $runtime->scheduleOnce(Duration::millis(200), static function () use ($system): void {
            $system->shutdown(Duration::seconds(1));
        });

        $system->run();

        self::assertSame([42, 99], $processedIds);
    }
}
