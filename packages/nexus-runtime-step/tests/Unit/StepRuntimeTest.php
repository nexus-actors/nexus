<?php
declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Step\Tests\Unit;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\BehaviorWithState;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Runtime\Step\StepRuntime;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

final class StepRuntimeTest extends TestCase
{
    private StepRuntime $runtime;
    private ActorSystem $system;

    #[Test]
    public function name_returns_step(): void
    {
        self::assertSame('step', $this->runtime->name());
    }

    #[Test]
    public function step_returns_false_when_idle(): void
    {
        self::assertFalse($this->runtime->step());
    }

    #[Test]
    public function step_processes_one_message(): void
    {
        $count = 0;

        /** @var Behavior<object> $behavior */
        $behavior = Behavior::receive(static function (ActorContext $ctx, object $msg) use (&$count): Behavior {
            $count++;

            return Behavior::same();
        });

        $ref = $this->system->spawn(Props::fromBehavior($behavior), 'actor');
        $ref->tell(new stdClass());

        $this->runtime->step();

        self::assertSame(1, $count);
    }

    #[Test]
    public function step_processes_messages_one_at_a_time(): void
    {
        $count = 0;

        /** @var Behavior<object> $behavior */
        $behavior = Behavior::receive(static function (ActorContext $ctx, object $msg) use (&$count): Behavior {
            $count++;

            return Behavior::same();
        });

        $ref = $this->system->spawn(Props::fromBehavior($behavior), 'actor');
        $ref->tell(new stdClass());
        $ref->tell(new stdClass());
        $ref->tell(new stdClass());

        $this->runtime->step();
        self::assertSame(1, $count);

        $this->runtime->step();
        self::assertSame(2, $count);

        $this->runtime->step();
        self::assertSame(3, $count);

        // No more messages
        self::assertFalse($this->runtime->step());
        self::assertSame(3, $count);
    }

    #[Test]
    public function drain_processes_all_pending(): void
    {
        $count = 0;

        /** @var Behavior<object> $behavior */
        $behavior = Behavior::receive(static function (ActorContext $ctx, object $msg) use (&$count): Behavior {
            $count++;

            return Behavior::same();
        });

        $ref = $this->system->spawn(Props::fromBehavior($behavior), 'actor');
        $ref->tell(new stdClass());
        $ref->tell(new stdClass());
        $ref->tell(new stdClass());

        $this->runtime->drain();

        self::assertSame(3, $count);
    }

    #[Test]
    public function run_drains_all_messages(): void
    {
        $count = 0;

        /** @var Behavior<object> $behavior */
        $behavior = Behavior::receive(static function (ActorContext $ctx, object $msg) use (&$count): Behavior {
            $count++;

            return Behavior::same();
        });

        $ref = $this->system->spawn(Props::fromBehavior($behavior), 'actor');
        $ref->tell(new stdClass());
        $ref->tell(new stdClass());

        $this->system->run();

        self::assertSame(2, $count);
    }

    #[Test]
    public function advance_time_fires_scheduled_timers(): void
    {
        $fired = false;

        $this->runtime->scheduleOnce(Duration::seconds(5), static function () use (&$fired): void {
            $fired = true;
        });

        self::assertFalse($fired);

        $this->runtime->advanceTime(Duration::seconds(3));
        self::assertFalse($fired);

        $this->runtime->advanceTime(Duration::seconds(3));
        self::assertTrue($fired);
    }

    #[Test]
    public function advance_time_fires_repeating_timers(): void
    {
        $count = 0;

        $this->runtime->scheduleRepeatedly(
            Duration::seconds(1),
            Duration::seconds(1),
            static function () use (&$count): void {
                $count++;
            },
        );

        $this->runtime->advanceTime(Duration::millis(500));
        self::assertSame(0, $count);

        $this->runtime->advanceTime(Duration::millis(600));
        self::assertSame(1, $count);

        $this->runtime->advanceTime(Duration::seconds(1));
        self::assertSame(2, $count);

        $this->runtime->advanceTime(Duration::seconds(1));
        self::assertSame(3, $count);
    }

    #[Test]
    public function cancelled_timer_does_not_fire(): void
    {
        $fired = false;

        $cancellable = $this->runtime->scheduleOnce(Duration::seconds(1), static function () use (&$fired): void {
            $fired = true;
        });

        $cancellable->cancel();
        $this->runtime->advanceTime(Duration::seconds(10));

        self::assertFalse($fired);
    }

    #[Test]
    public function deterministic_ordering_across_actors(): void
    {
        $order = [];

        /** @var Behavior<object> $behaviorA */
        $behaviorA = Behavior::receive(static function (ActorContext $ctx, object $msg) use (&$order): Behavior {
            $order[] = 'A';

            return Behavior::same();
        });

        /** @var Behavior<object> $behaviorB */
        $behaviorB = Behavior::receive(static function (ActorContext $ctx, object $msg) use (&$order): Behavior {
            $order[] = 'B';

            return Behavior::same();
        });

        $refA = $this->system->spawn(Props::fromBehavior($behaviorA), 'actor-a');
        $refB = $this->system->spawn(Props::fromBehavior($behaviorB), 'actor-b');

        $refA->tell(new stdClass());
        $refB->tell(new stdClass());

        $this->runtime->step();
        $this->runtime->step();

        // A was spawned first, so A processes first
        self::assertSame(['A', 'B'], $order);
    }

    #[Test]
    public function cascading_messages(): void
    {
        $order = [];

        /** @var Behavior<object> $forwarder */
        $forwarder = Behavior::receive(static function (ActorContext $ctx, object $msg) use (&$order): Behavior {
            $order[] = 'forwarder';

            if ($msg instanceof stdClass && isset($msg->target)) {
                $msg->target->tell(new stdClass());
            }

            return Behavior::same();
        });

        /** @var Behavior<object> $receiver */
        $receiver = Behavior::receive(static function (ActorContext $ctx, object $msg) use (&$order): Behavior {
            $order[] = 'receiver';

            return Behavior::same();
        });

        $receiverRef = $this->system->spawn(Props::fromBehavior($receiver), 'receiver');
        $forwarderRef = $this->system->spawn(Props::fromBehavior($forwarder), 'forwarder');

        $msg = new stdClass();
        $msg->target = $receiverRef;
        $forwarderRef->tell($msg);

        // Step 1: forwarder processes the message and tells receiver
        $this->runtime->step();
        self::assertSame(['forwarder'], $order);
        self::assertSame(1, $this->runtime->pendingMessageCount());

        // Step 2: receiver processes the cascaded message
        $this->runtime->step();
        self::assertSame(['forwarder', 'receiver'], $order);
        self::assertSame(0, $this->runtime->pendingMessageCount());
    }

    #[Test]
    public function stateful_actor_maintains_state_across_steps(): void
    {
        /** @var list<int> $observed */
        $observed = [];

        /** @var Behavior<object> $counter */
        $counter = Behavior::withState(
            0,
            static function (ActorContext $ctx, object $msg, int $count) use (&$observed): BehaviorWithState {
                $next = $count + 1;
                $observed[] = $next;

                return BehaviorWithState::next($next);
            },
        );

        $ref = $this->system->spawn(Props::fromBehavior($counter), 'counter');
        $ref->tell(new stdClass());
        $ref->tell(new stdClass());
        $ref->tell(new stdClass());

        $this->runtime->step();
        self::assertSame([1], $observed);

        $this->runtime->step();
        self::assertSame([1, 2], $observed);

        $this->runtime->step();
        self::assertSame([1, 2, 3], $observed);
    }

    #[Test]
    public function pending_message_count(): void
    {
        /** @var Behavior<object> $behavior */
        $behavior = Behavior::receive(static function (ActorContext $ctx, object $msg): Behavior {
            return Behavior::same();
        });

        $ref = $this->system->spawn(Props::fromBehavior($behavior), 'actor');

        self::assertSame(0, $this->runtime->pendingMessageCount());

        $ref->tell(new stdClass());
        $ref->tell(new stdClass());
        self::assertSame(2, $this->runtime->pendingMessageCount());

        $this->runtime->step();
        self::assertSame(1, $this->runtime->pendingMessageCount());

        $this->runtime->step();
        self::assertSame(0, $this->runtime->pendingMessageCount());
    }

    #[Test]
    public function is_idle(): void
    {
        /** @var Behavior<object> $behavior */
        $behavior = Behavior::receive(static function (ActorContext $ctx, object $msg): Behavior {
            return Behavior::same();
        });

        $ref = $this->system->spawn(Props::fromBehavior($behavior), 'actor');

        // Need to start fibers first
        $this->runtime->step(); // starts fiber, returns false (no messages)

        self::assertTrue($this->runtime->isIdle());

        $ref->tell(new stdClass());
        self::assertFalse($this->runtime->isIdle());

        $this->runtime->step();
        self::assertTrue($this->runtime->isIdle());
    }

    #[Test]
    public function virtual_clock_does_not_advance_automatically(): void
    {
        $t1 = $this->runtime->clock()->now();

        /** @var Behavior<object> $behavior */
        $behavior = Behavior::receive(static function (ActorContext $ctx, object $msg): Behavior {
            return Behavior::same();
        });

        $ref = $this->system->spawn(Props::fromBehavior($behavior), 'actor');
        $ref->tell(new stdClass());
        $this->runtime->step();

        $t2 = $this->runtime->clock()->now();

        self::assertEquals($t1, $t2);
    }

    protected function setUp(): void
    {
        $this->runtime = new StepRuntime();
        $this->system = ActorSystem::create('step-test', $this->runtime, clock: $this->runtime->clock());
    }
}
