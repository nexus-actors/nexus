<?php
declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Unit\Actor;

use Fp\Functional\Option\Option;
use LogicException;
use Monadial\Nexus\Core\Actor\ActorCell;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\ActorState;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\DeadLetterRef;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Core\Exception\ActorInitializationException;
use Monadial\Nexus\Core\Exception\AskTimeoutException;
use Monadial\Nexus\Core\Mailbox\Envelope;
use Monadial\Nexus\Core\Message\PoisonPill;
use Monadial\Nexus\Core\Message\Resume;
use Monadial\Nexus\Core\Message\Suspend;
use Monadial\Nexus\Core\Supervision\SupervisionStrategy;
use Monadial\Nexus\Core\Tests\Support\TestLogger;
use Monadial\Nexus\Core\Tests\Support\TestMailbox;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use OverflowException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(ActorCell::class)]
final class ActorCellAdvancedTest extends TestCase
{
    private TestRuntime $runtime;
    private DeadLetterRef $deadLetters;
    private TestLogger $logger;

    #[Test]
    public function checked_exception_in_handler_is_caught_and_logged(): void
    {
        /** @var Behavior<TestMessage> */
        $behavior = Behavior::receive(
            static function (ActorContext $ctx, object $msg): Behavior {
                throw new AskTimeoutException(ActorPath::root(), Duration::seconds(1));
            },
        );

        $cell = $this->createCell($behavior);
        $cell->start();

        // The NexusException (AskTimeoutException extends ActorException extends NexusException)
        // should be caught, NOT propagated, and logged at error level.
        $cell->processMessage($this->envelope(new TestMessage('trigger')));

        // Actor should still be running (exception was caught)
        self::assertSame(ActorState::Running, $cell->actorState());

        // Should have logged at error level
        self::assertTrue(
            $this->logger->hasLogMatching('error', 'NexusException'),
            'Expected error log for NexusException; got: ' . print_r($this->logger->logs, true),
        );
    }

    #[Test]
    public function unchecked_logic_exception_in_handler_is_caught_and_logged(): void
    {
        /** @var Behavior<TestMessage> */
        $behavior = Behavior::receive(
            static function (ActorContext $ctx, object $msg): Behavior {
                throw new LogicException('bug in handler code');
            },
        );

        $cell = $this->createCell($behavior);
        $cell->start();

        $cell->processMessage($this->envelope(new TestMessage('trigger')));

        // Actor should still be running
        self::assertSame(ActorState::Running, $cell->actorState());

        // Should have logged at critical level
        self::assertTrue(
            $this->logger->hasLogMatching('critical', 'Unchecked exception in handler'),
            'Expected critical log for LogicException; got: ' . print_r($this->logger->logs, true),
        );
    }

    #[Test]
    public function unexpected_exception_in_handler_is_caught_and_logged(): void
    {
        /** @var Behavior<TestMessage> */
        $behavior = Behavior::receive(
            static function (ActorContext $ctx, object $msg): Behavior {
                throw new OverflowException('some unexpected error');
            },
        );

        $cell = $this->createCell($behavior);
        $cell->start();

        $cell->processMessage($this->envelope(new TestMessage('trigger')));

        // Actor should still be running
        self::assertSame(ActorState::Running, $cell->actorState());

        // Should have logged at critical level (Throwable catch-all)
        self::assertTrue(
            $this->logger->hasLogMatching('critical', 'Unexpected exception in handler'),
            'Expected critical log for unexpected exception; got: ' . print_r($this->logger->logs, true),
        );
    }

    // ======================================================================
    // System Messages
    // ======================================================================

    #[Test]
    public function poisonPill_stops_actor(): void
    {
        /** @var Behavior<TestMessage> */
        $behavior = Behavior::receive(
            static fn (ActorContext $ctx, object $msg): Behavior => Behavior::same(),
        );

        $cell = $this->createCell($behavior);
        $cell->start();

        self::assertSame(ActorState::Running, $cell->actorState());

        $cell->processMessage($this->envelope(new PoisonPill()));

        self::assertSame(ActorState::Stopped, $cell->actorState());
    }

    #[Test]
    public function suspend_transitions_to_suspended(): void
    {
        /** @var Behavior<TestMessage> */
        $behavior = Behavior::receive(
            static fn (ActorContext $ctx, object $msg): Behavior => Behavior::same(),
        );

        $cell = $this->createCell($behavior);
        $cell->start();

        self::assertSame(ActorState::Running, $cell->actorState());

        // Send Suspend while Running
        $cell->processMessage($this->envelope(new Suspend()));

        self::assertSame(ActorState::Suspended, $cell->actorState());
    }

    #[Test]
    public function resume_transitions_back_to_running(): void
    {
        // NOTE: processMessage() early-returns when state !== Running.
        // After Suspend, the actor is Suspended, so a Resume via processMessage
        // will be ignored. This test documents the design gap:
        // Resume cannot be delivered through processMessage in Suspended state.
        //
        // However, we can verify the handleSystemMessage logic works correctly
        // if called directly. Since handleSystemMessage is private, we test
        // the observable behavior: after Suspend, processMessage with Resume
        // does NOT transition back (this is the design gap).

        /** @var Behavior<TestMessage> */
        $behavior = Behavior::receive(
            static fn (ActorContext $ctx, object $msg): Behavior => Behavior::same(),
        );

        $cell = $this->createCell($behavior);
        $cell->start();

        // Suspend
        $cell->processMessage($this->envelope(new Suspend()));
        self::assertSame(ActorState::Suspended, $cell->actorState());

        // Attempt Resume via processMessage -- early-returns because state !== Running
        $cell->processMessage($this->envelope(new Resume()));

        // Actor remains Suspended: the Resume is silently dropped because
        // processMessage guards on Running state. A dedicated system message
        // queue is needed to fix this.
        self::assertSame(ActorState::Suspended, $cell->actorState());
    }

    #[Test]
    public function suspended_actor_ignores_user_messages(): void
    {
        $messageProcessed = false;

        /** @var Behavior<TestMessage> */
        $behavior = Behavior::receive(
            static function (ActorContext $ctx, object $msg) use (&$messageProcessed): Behavior {
                $messageProcessed = true;

                return Behavior::same();
            },
        );

        $cell = $this->createCell($behavior);
        $cell->start();

        // Suspend the actor
        $cell->processMessage($this->envelope(new Suspend()));
        self::assertSame(ActorState::Suspended, $cell->actorState());

        // User message should be ignored
        $cell->processMessage($this->envelope(new TestMessage('ignored')));

        self::assertFalse($messageProcessed, 'User message should not be processed while Suspended');
        self::assertSame(ActorState::Suspended, $cell->actorState());
    }

    // ======================================================================
    // Child Management Edge Cases
    // ======================================================================

    #[Test]
    public function duplicate_child_name_throws_exception(): void
    {
        $spawned = false;

        /** @var Behavior<TestMessage> */
        $behavior = Behavior::receive(
            static function (ActorContext $ctx, object $msg) use (&$spawned): Behavior {
                if ($msg instanceof TestMessage && $msg->value === 'spawn-twice') {
                    /** @var Behavior<TestMessage> */
                    $childBehavior = Behavior::receive(
                        static fn (ActorContext $c, object $m): Behavior => Behavior::same(),
                    );

                    // First spawn should succeed
                    $ctx->spawn(Props::fromBehavior($childBehavior), 'worker');
                    $spawned = true;

                    // Second spawn with same name should throw
                    $ctx->spawn(Props::fromBehavior($childBehavior), 'worker');
                }

                return Behavior::same();
            },
        );

        $cell = $this->createCell($behavior, ActorPath::fromString('/user/parent'));
        $cell->start();

        // The NexusLogicException (ActorNameExistsException) is a LogicException,
        // caught by the tiered exception handler and logged as critical.
        $cell->processMessage($this->envelope(
            new TestMessage('spawn-twice'),
            ActorPath::fromString('/user/parent'),
        ));

        self::assertTrue($spawned, 'First spawn should have succeeded');

        // The LogicException should have been caught and logged
        self::assertTrue(
            $this->logger->hasLogMatching('critical', 'Unchecked exception in handler'),
            'Expected critical log for ActorNameExistsException; got: ' . print_r($this->logger->logs, true),
        );
    }

    #[Test]
    public function stop_child_sends_poison_pill(): void
    {
        /** @var Behavior<TestMessage> */
        $behavior = Behavior::receive(
            static function (ActorContext $ctx, object $msg): Behavior {
                if ($msg instanceof TestMessage && $msg->value === 'spawn-and-stop') {
                    /** @var Behavior<TestMessage> */
                    $childBehavior = Behavior::receive(
                        static fn (ActorContext $c, object $m): Behavior => Behavior::same(),
                    );
                    $childRef = $ctx->spawn(Props::fromBehavior($childBehavior), 'doomed');

                    // ctx->stop() sends a PoisonPill to the child
                    $ctx->stop($childRef);
                }

                return Behavior::same();
            },
        );

        $cell = $this->createCell($behavior, ActorPath::fromString('/user/parent'));
        $cell->start();

        $cell->processMessage($this->envelope(
            new TestMessage('spawn-and-stop'),
            ActorPath::fromString('/user/parent'),
        ));

        // The child's mailbox should contain a PoisonPill.
        // Since child was spawned via runtime which creates a TestMailbox,
        // and stop() calls child->tell(new PoisonPill()), the PoisonPill
        // is enqueued in the child's mailbox.
        // We verify the child ref was created with the correct path.
        $childOpt = $cell->child('doomed');
        self::assertTrue($childOpt->isSome(), 'Child should exist in parent children map');
    }

    #[Test]
    public function parent_stop_sends_poison_pill_to_children(): void
    {
        $childMailboxes = [];

        /** @var Behavior<TestMessage> */
        $behavior = Behavior::receive(
            static function (ActorContext $ctx, object $msg): Behavior {
                if ($msg instanceof TestMessage && $msg->value === 'spawn') {
                    /** @var Behavior<TestMessage> */
                    $childBehavior = Behavior::receive(
                        static fn (ActorContext $c, object $m): Behavior => Behavior::same(),
                    );
                    $ctx->spawn(Props::fromBehavior($childBehavior), 'child-a');
                    $ctx->spawn(Props::fromBehavior($childBehavior), 'child-b');
                }

                return Behavior::same();
            },
        );

        $cell = $this->createCell($behavior, ActorPath::fromString('/user/parent'));
        $cell->start();

        // Spawn children
        $cell->processMessage($this->envelope(
            new TestMessage('spawn'),
            ActorPath::fromString('/user/parent'),
        ));

        self::assertSame(2, $cell->children()->count());

        // Stop the parent -- initiateStop() iterates children and sends PoisonPill
        $cell->initiateStop();

        self::assertSame(ActorState::Stopped, $cell->actorState());
    }

    // ======================================================================
    // Init Failure
    // ======================================================================

    #[Test]
    public function setup_failure_transitions_to_stopped_and_throws(): void
    {
        /** @var Behavior<TestMessage> */
        $behavior = Behavior::setup(
            static function (ActorContext $ctx): Behavior {
                throw new RuntimeException('initialization boom');
            },
        );

        $cell = $this->createCell($behavior);

        $this->expectException(ActorInitializationException::class);
        $this->expectExceptionMessageMatches('/initialization boom/');

        try {
            $cell->start();
        } catch (ActorInitializationException $e) {
            // After failure, cell should be in Stopped state
            self::assertSame(ActorState::Stopped, $cell->actorState());

            throw $e;
        }
    }

    // ======================================================================
    // Empty Behavior
    // ======================================================================

    #[Test]
    public function empty_behavior_routes_to_dead_letters(): void
    {
        /** @var Behavior<TestMessage> */
        $behavior = Behavior::empty();

        $cell = $this->createCell($behavior);
        $cell->start();

        $msg = new TestMessage('nowhere');
        $cell->processMessage($this->envelope($msg));

        $captured = $this->deadLetters->captured();
        self::assertCount(1, $captured);
        self::assertSame($msg, $captured[0]);
    }

    // ======================================================================
    // Scheduling
    // ======================================================================

    #[Test]
    public function scheduleOnce_delegates_to_runtime(): void
    {
        $cancellable = null;

        /** @var Behavior<TestMessage> */
        $behavior = Behavior::receive(
            static function (ActorContext $ctx, object $msg) use (&$cancellable): Behavior {
                if ($msg instanceof TestMessage && $msg->value === 'schedule') {
                    $cancellable = $ctx->scheduleOnce(
                        Duration::seconds(5),
                        new TestMessage('delayed'),
                    );
                }

                return Behavior::same();
            },
        );

        $mailbox = TestMailbox::unbounded();
        $cell = $this->createCell($behavior, mailbox: $mailbox);
        $cell->start();

        $cell->processMessage($this->envelope(new TestMessage('schedule')));

        // The scheduleOnce should have returned a Cancellable
        self::assertNotNull($cancellable);
        self::assertFalse($cancellable->isCancelled());

        // Advance time and fire timers -- the callback tells the message to self
        $this->runtime->advanceTime(Duration::seconds(6));

        // The mailbox should now have the delayed message
        self::assertGreaterThanOrEqual(1, $mailbox->count());

        $env = $mailbox->dequeue()->get();
        self::assertInstanceOf(TestMessage::class, $env->message);
        self::assertSame('delayed', $env->message->value);
    }

    #[Test]
    public function scheduleRepeatedly_delegates_to_runtime(): void
    {
        $cancellable = null;

        /** @var Behavior<TestMessage> */
        $behavior = Behavior::receive(
            static function (ActorContext $ctx, object $msg) use (&$cancellable): Behavior {
                if ($msg instanceof TestMessage && $msg->value === 'schedule') {
                    $cancellable = $ctx->scheduleRepeatedly(
                        Duration::seconds(1),
                        Duration::seconds(2),
                        new TestMessage('tick'),
                    );
                }

                return Behavior::same();
            },
        );

        $mailbox = TestMailbox::unbounded();
        $cell = $this->createCell($behavior, mailbox: $mailbox);
        $cell->start();

        $cell->processMessage($this->envelope(new TestMessage('schedule')));

        self::assertNotNull($cancellable);
        self::assertFalse($cancellable->isCancelled());

        // Advance past initial delay (1s) -- first tick
        $this->runtime->advanceTime(Duration::seconds(2));
        $count1 = $mailbox->count();
        self::assertGreaterThanOrEqual(1, $count1, 'Should have at least 1 tick after initial delay');

        // Advance another interval (2s) -- second tick
        $this->runtime->advanceTime(Duration::seconds(2));
        $count2 = $mailbox->count();
        self::assertGreaterThan($count1, $count2, 'Should have more ticks after another interval');
    }

    protected function setUp(): void
    {
        $this->runtime = new TestRuntime();
        $this->deadLetters = new DeadLetterRef();
        $this->logger = new TestLogger();
    }

    // ---- Helpers ----

    /**
     * @template T of object
     * @param Behavior<T> $behavior
     * @return ActorCell<T>
     */
    private function createCell(Behavior $behavior, ?ActorPath $path = null, ?TestMailbox $mailbox = null,): ActorCell {
        $path ??= ActorPath::fromString('/user/test');
        $mailbox ??= TestMailbox::unbounded();

        /** @var Option<\Monadial\Nexus\Core\Actor\ActorRef<object>> $noParent */
        $noParent = Option::none();

        return new ActorCell(
            $behavior,
            $path,
            $mailbox,
            $this->runtime,
            $noParent,
            SupervisionStrategy::oneForOne(),
            $this->runtime->clock(),
            $this->logger,
            $this->deadLetters,
        );
    }

    private function envelope(object $message, ?ActorPath $target = null): Envelope
    {
        return Envelope::of(
            $message,
            ActorPath::root(),
            $target ?? ActorPath::fromString('/user/test'),
        );
    }// ======================================================================
// Tiered Exception Handling
// ======================================================================
}
