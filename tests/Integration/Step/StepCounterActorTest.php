<?php
declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Step;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\BehaviorWithState;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Runtime\Step\StepRuntime;
use Monadial\Nexus\Tests\Integration\Step\Messages\CountReply;
use Monadial\Nexus\Tests\Integration\Step\Messages\GetCount;
use Monadial\Nexus\Tests\Integration\Step\Messages\Increment;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StepCounterActorTest extends TestCase
{
    private StepRuntime $runtime;
    private ActorSystem $system;

    #[Test]
    public function counter_increments_step_by_step(): void
    {
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

        $counterRef = $this->system->spawn(Props::fromBehavior($counterBehavior), 'counter');

        // Send 5 increments
        for ($i = 0; $i < 5; $i++) {
            $counterRef->tell(new Increment());
        }

        // Step through each increment one at a time
        for ($i = 0; $i < 5; $i++) {
            self::assertTrue($this->runtime->step());
        }

        // Probe to capture reply
        /** @var list<object> $captured */
        $captured = [];

        /** @var Behavior<object> $probeBehavior */
        $probeBehavior = Behavior::receive(static function (ActorContext $ctx, object $msg) use (&$captured): Behavior {
            $captured[] = $msg;

            return Behavior::same();
        });

        $probeRef = $this->system->spawn(Props::fromBehavior($probeBehavior), 'probe');

        // Ask counter for its count
        $counterRef->tell(new GetCount($probeRef));

        // Step: counter processes GetCount, tells probe
        $this->runtime->step();
        // Step: probe processes CountReply
        $this->runtime->step();

        self::assertCount(1, $captured);
        self::assertInstanceOf(CountReply::class, $captured[0]);
        self::assertSame(5, $captured[0]->count);
    }

    #[Test]
    public function counter_with_drain(): void
    {
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

        $counterRef = $this->system->spawn(Props::fromBehavior($counterBehavior), 'counter');

        /** @var list<object> $captured */
        $captured = [];

        /** @var Behavior<object> $probeBehavior */
        $probeBehavior = Behavior::receive(static function (ActorContext $ctx, object $msg) use (&$captured): Behavior {
            $captured[] = $msg;

            return Behavior::same();
        });

        $probeRef = $this->system->spawn(Props::fromBehavior($probeBehavior), 'probe');

        // Send 3 increments then ask for count
        for ($i = 0; $i < 3; $i++) {
            $counterRef->tell(new Increment());
        }

        $counterRef->tell(new GetCount($probeRef));

        // Drain processes all: 3 increments + GetCount + CountReply to probe
        $this->runtime->drain();

        self::assertCount(1, $captured);
        self::assertInstanceOf(CountReply::class, $captured[0]);
        self::assertSame(3, $captured[0]->count);
    }

    #[Test]
    public function multiple_queries_at_different_counts(): void
    {
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

        $counterRef = $this->system->spawn(Props::fromBehavior($counterBehavior), 'counter');

        /** @var list<object> $captured */
        $captured = [];

        /** @var Behavior<object> $probeBehavior */
        $probeBehavior = Behavior::receive(static function (ActorContext $ctx, object $msg) use (&$captured): Behavior {
            $captured[] = $msg;

            return Behavior::same();
        });

        $probeRef = $this->system->spawn(Props::fromBehavior($probeBehavior), 'probe');

        // Increment 3 times, query, increment 2 more, query again
        for ($i = 0; $i < 3; $i++) {
            $counterRef->tell(new Increment());
        }

        $counterRef->tell(new GetCount($probeRef));

        for ($i = 0; $i < 2; $i++) {
            $counterRef->tell(new Increment());
        }

        $counterRef->tell(new GetCount($probeRef));

        $this->runtime->drain();

        self::assertCount(2, $captured);
        self::assertInstanceOf(CountReply::class, $captured[0]);
        self::assertSame(3, $captured[0]->count);
        self::assertInstanceOf(CountReply::class, $captured[1]);
        self::assertSame(5, $captured[1]->count);
    }

    protected function setUp(): void
    {
        $this->runtime = new StepRuntime();
        $this->system = ActorSystem::create('step-counter-test', $this->runtime, clock: $this->runtime->clock());
    }
}
