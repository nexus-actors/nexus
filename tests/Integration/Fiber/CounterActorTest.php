<?php
declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Fiber;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\BehaviorWithState;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use Monadial\Nexus\Tests\Integration\Fiber\Messages\CountReply;
use Monadial\Nexus\Tests\Integration\Fiber\Messages\GetCount;
use Monadial\Nexus\Tests\Integration\Fiber\Messages\Increment;
use PHPUnit\Framework\TestCase;

final class CounterActorTest extends TestCase
{
    /**
     * Test: A stateful counter actor increments and replies with the count.
     *
     * Flow:
     *   1. Spawn a counter actor with initial state 0
     *   2. Send 5 Increment messages
     *   3. Spawn a probe actor that captures replies
     *   4. Send GetCount with probe's ref
     *   5. Schedule shutdown
     *   6. Run the system
     *   7. Assert probe captured CountReply(5)
     */
    public function testCounterIncrementsThenReplies(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('counter-test', $runtime);

        // Stateful counter behavior
        /** @var Behavior<object> $counterBehavior */
        $counterBehavior = Behavior::withState(
            0,
            static function (ActorContext $ctx, object $msg, int $count): BehaviorWithState {
                if ($msg instanceof Increment) {
                    return BehaviorWithState::next($count + 1);
                }
    
                if ($msg instanceof GetCount) {
                    $msg->replyTo->tell(new CountReply($count));
    
                    return BehaviorWithState::same();
                }
    
                return BehaviorWithState::same();
            },
        );

        $counterRef = $system->spawn(Props::fromBehavior($counterBehavior), 'counter');

        // Send 5 increment messages
        for ($i = 0; $i < 5; $i++) {
            $counterRef->tell(new Increment());
        }

        // Probe actor that captures received messages
        /** @var list<object> $captured */
        $captured = [];

        /** @var Behavior<object> $probeBehavior */
        $probeBehavior = Behavior::receive(static function (ActorContext $ctx, object $msg) use (&$captured): Behavior {
            $captured[] = $msg;

            return Behavior::same();
        });

        $probeRef = $system->spawn(Props::fromBehavior($probeBehavior), 'probe');

        // Ask counter for its count, sending reply to probe
        $counterRef->tell(new GetCount($probeRef));

        // Schedule shutdown so run() eventually exits
        $runtime->scheduleOnce(Duration::millis(200), static function () use ($system): void {
            $system->shutdown(Duration::seconds(1));
        });

        $system->run();

        // Verify the probe captured exactly one CountReply with count 5
        self::assertCount(1, $captured);
        self::assertInstanceOf(CountReply::class, $captured[0]);
        self::assertSame(5, $captured[0]->count);
    }

    /**
     * Test: Counter with zero increments replies with 0.
     */
    public function testCounterWithNoIncrements(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('counter-zero-test', $runtime);

        /** @var Behavior<object> $counterBehavior */
        $counterBehavior = Behavior::withState(
            0,
            static function (ActorContext $ctx, object $msg, int $count): BehaviorWithState {
                if ($msg instanceof Increment) {
                    return BehaviorWithState::next($count + 1);
                }
    
                if ($msg instanceof GetCount) {
                    $msg->replyTo->tell(new CountReply($count));
    
                    return BehaviorWithState::same();
                }
    
                return BehaviorWithState::same();
            },
        );

        $counterRef = $system->spawn(Props::fromBehavior($counterBehavior), 'counter');

        /** @var list<object> $captured */
        $captured = [];

        /** @var Behavior<object> $probeBehavior */
        $probeBehavior = Behavior::receive(static function (ActorContext $ctx, object $msg) use (&$captured): Behavior {
            $captured[] = $msg;

            return Behavior::same();
        });

        $probeRef = $system->spawn(Props::fromBehavior($probeBehavior), 'probe');

        $counterRef->tell(new GetCount($probeRef));

        $runtime->scheduleOnce(Duration::millis(200), static function () use ($system): void {
            $system->shutdown(Duration::seconds(1));
        });

        $system->run();

        self::assertCount(1, $captured);
        self::assertInstanceOf(CountReply::class, $captured[0]);
        self::assertSame(0, $captured[0]->count);
    }

    /**
     * Test: Multiple GetCount calls return consistent state.
     */
    public function testMultipleGetCountCalls(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('counter-multi-test', $runtime);

        /** @var Behavior<object> $counterBehavior */
        $counterBehavior = Behavior::withState(
            0,
            static function (ActorContext $ctx, object $msg, int $count): BehaviorWithState {
                if ($msg instanceof Increment) {
                    return BehaviorWithState::next($count + 1);
                }
    
                if ($msg instanceof GetCount) {
                    $msg->replyTo->tell(new CountReply($count));
    
                    return BehaviorWithState::same();
                }
    
                return BehaviorWithState::same();
            },
        );

        $counterRef = $system->spawn(Props::fromBehavior($counterBehavior), 'counter');

        /** @var list<object> $captured */
        $captured = [];

        /** @var Behavior<object> $probeBehavior */
        $probeBehavior = Behavior::receive(static function (ActorContext $ctx, object $msg) use (&$captured): Behavior {
            $captured[] = $msg;

            return Behavior::same();
        });

        $probeRef = $system->spawn(Props::fromBehavior($probeBehavior), 'probe');

        // Increment 3 times, ask, increment 2 more, ask again
        for ($i = 0; $i < 3; $i++) {
            $counterRef->tell(new Increment());
        }

        $counterRef->tell(new GetCount($probeRef));

        for ($i = 0; $i < 2; $i++) {
            $counterRef->tell(new Increment());
        }

        $counterRef->tell(new GetCount($probeRef));

        $runtime->scheduleOnce(Duration::millis(200), static function () use ($system): void {
            $system->shutdown(Duration::seconds(1));
        });

        $system->run();

        self::assertCount(2, $captured);
        self::assertInstanceOf(CountReply::class, $captured[0]);
        self::assertSame(3, $captured[0]->count);
        self::assertInstanceOf(CountReply::class, $captured[1]);
        self::assertSame(5, $captured[1]->count);
    }
}
