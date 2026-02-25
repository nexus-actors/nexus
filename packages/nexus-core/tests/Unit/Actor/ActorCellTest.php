<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Unit\Actor;

use Fp\Functional\Option\Option;
use Monadial\Nexus\Core\Actor\ActorCell;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\ActorState;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\BehaviorWithState;
use Monadial\Nexus\Core\Actor\DeadLetterRef;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Actor\StashBuffer;
use Monadial\Nexus\Core\Actor\TimerScheduler;
use Monadial\Nexus\Core\Lifecycle\PostStop;
use Monadial\Nexus\Core\Lifecycle\PreStart;
use Monadial\Nexus\Core\Lifecycle\Signal;
use Monadial\Nexus\Core\Mailbox\Envelope;
use Monadial\Nexus\Core\Supervision\SupervisionStrategy;
use Monadial\Nexus\Core\Tests\Support\TestMailbox;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final readonly class TestMessage
{
    public function __construct(public string $value) {}
}

final readonly class TestReply
{
    public function __construct(public string $value) {}
}

/**
 * @psalm-suppress InvalidArgument, PropertyNotSetInConstructor, UnusedClosureParam, PossiblyNullArgument, UnnecessaryVarAnnotation, MixedArgumentTypeCoercion
 */
#[CoversClass(ActorCell::class)]
final class ActorCellTest extends TestCase
{
    private TestRuntime $runtime;
    private DeadLetterRef $deadLetters;
    private NullLogger $logger;

    #[Test]
    public function starts_in_new_state(): void
    {
        $behavior = Behavior::receive(
            static fn(ActorContext $ctx, object $msg): Behavior => Behavior::same(),
        );

        $cell = $this->createCell($behavior);

        self::assertSame(ActorState::New, $cell->actorState());
    }

    #[Test]
    public function start_transitions_to_running(): void
    {
        $behavior = Behavior::receive(
            static fn(ActorContext $ctx, object $msg): Behavior => Behavior::same(),
        );

        $cell = $this->createCell($behavior);
        $cell->start();

        self::assertSame(ActorState::Running, $cell->actorState());
    }

    #[Test]
    public function start_delivers_prestart_signal(): void
    {
        $signalReceived = false;

        /** @var Behavior<TestMessage> */
        $behavior = Behavior::receive(
            static fn(ActorContext $ctx, object $msg): Behavior => Behavior::same(),
        )->onSignal(static function (ActorContext $ctx, Signal $signal) use (&$signalReceived): Behavior {
            if ($signal instanceof PreStart) {
                $signalReceived = true;
            }

            return Behavior::same();
        });

        $cell = $this->createCell($behavior);
        $cell->start();

        self::assertTrue($signalReceived);
    }

    #[Test]
    public function stop_transitions_to_stopped(): void
    {
        $behavior = Behavior::receive(
            static fn(ActorContext $ctx, object $msg): Behavior => Behavior::same(),
        );

        $cell = $this->createCell($behavior);
        $cell->start();
        $cell->initiateStop();

        self::assertSame(ActorState::Stopped, $cell->actorState());
    }

    #[Test]
    public function stop_delivers_poststop_signal(): void
    {
        $signalReceived = false;

        /** @var Behavior<TestMessage> */
        $behavior = Behavior::receive(
            static fn(ActorContext $ctx, object $msg): Behavior => Behavior::same(),
        )->onSignal(static function (ActorContext $ctx, Signal $signal) use (&$signalReceived): Behavior {
            if ($signal instanceof PostStop) {
                $signalReceived = true;
            }

            return Behavior::same();
        });

        $cell = $this->createCell($behavior);
        $cell->start();
        $cell->initiateStop();

        self::assertTrue($signalReceived);
    }

    #[Test]
    public function stop_closes_mailbox(): void
    {
        $behavior = Behavior::receive(
            static fn(ActorContext $ctx, object $msg): Behavior => Behavior::same(),
        );

        $mailbox = TestMailbox::unbounded();
        $cell = $this->createCell($behavior, mailbox: $mailbox);
        $cell->start();
        $cell->initiateStop();

        self::assertTrue($mailbox->isClosed());
    }

    // ======================================================================
    // Message Dispatch Tests
    // ======================================================================

    #[Test]
    public function processes_user_message_via_behavior(): void
    {
        $received = '';

        /** @var Behavior<TestMessage> */
        $behavior = Behavior::receive(
            static function (ActorContext $ctx, object $msg) use (&$received): Behavior {
                if ($msg instanceof TestMessage) {
                    $received = $msg->value;
                }

                return Behavior::same();
            },
        );

        $cell = $this->createCell($behavior);
        $cell->start();

        $envelope = Envelope::of(
            new TestMessage('hello'),
            ActorPath::root(),
            ActorPath::fromString('/user/test'),
        );
        $cell->processMessage($envelope);

        self::assertSame('hello', $received);
    }

    #[Test]
    public function behavior_same_keeps_current_behavior(): void
    {
        $callCount = 0;

        /** @var Behavior<TestMessage> */
        $behavior = Behavior::receive(
            static function (ActorContext $ctx, object $msg) use (&$callCount): Behavior {
                $callCount++;

                return Behavior::same();
            },
        );

        $cell = $this->createCell($behavior);
        $cell->start();

        $envelope = Envelope::of(
            new TestMessage('first'),
            ActorPath::root(),
            ActorPath::fromString('/user/test'),
        );
        $cell->processMessage($envelope);

        $envelope2 = Envelope::of(
            new TestMessage('second'),
            ActorPath::root(),
            ActorPath::fromString('/user/test'),
        );
        $cell->processMessage($envelope2);

        self::assertSame(2, $callCount);
    }

    #[Test]
    public function behavior_stopped_initiates_stop(): void
    {
        /** @var Behavior<TestMessage> */
        $behavior = Behavior::receive(
            static fn(ActorContext $ctx, object $msg): Behavior => Behavior::stopped(),
        );

        $cell = $this->createCell($behavior);
        $cell->start();

        $envelope = Envelope::of(
            new TestMessage('stop-me'),
            ActorPath::root(),
            ActorPath::fromString('/user/test'),
        );
        $cell->processMessage($envelope);

        self::assertSame(ActorState::Stopped, $cell->actorState());
    }

    #[Test]
    public function behavior_swap_replaces_current(): void
    {
        $secondHandlerCalled = false;

        /** @var Behavior<TestMessage> */
        $secondBehavior = Behavior::receive(
            static function (ActorContext $ctx, object $msg) use (&$secondHandlerCalled): Behavior {
                $secondHandlerCalled = true;

                return Behavior::same();
            },
        );

        /** @var Behavior<TestMessage> */
        $firstBehavior = Behavior::receive(
            static fn(ActorContext $ctx, object $msg): Behavior => $secondBehavior,
        );

        $cell = $this->createCell($firstBehavior);
        $cell->start();

        // First message: triggers swap to secondBehavior
        $cell->processMessage(Envelope::of(
            new TestMessage('swap'),
            ActorPath::root(),
            ActorPath::fromString('/user/test'),
        ));

        // Second message: should go to secondBehavior
        $cell->processMessage(Envelope::of(
            new TestMessage('after-swap'),
            ActorPath::root(),
            ActorPath::fromString('/user/test'),
        ));

        self::assertTrue($secondHandlerCalled);
    }

    #[Test]
    public function unhandled_routes_to_dead_letters(): void
    {
        /** @var Behavior<TestMessage> */
        $behavior = Behavior::receive(
            static fn(ActorContext $ctx, object $msg): Behavior => Behavior::unhandled(),
        );

        $cell = $this->createCell($behavior);
        $cell->start();

        $msg = new TestMessage('lost');
        $cell->processMessage(Envelope::of(
            $msg,
            ActorPath::root(),
            ActorPath::fromString('/user/test'),
        ));

        $captured = $this->deadLetters->captured();
        self::assertCount(1, $captured);
        self::assertSame($msg, $captured[0]);
    }

    // ======================================================================
    // Signal Tests
    // ======================================================================

    #[Test]
    public function processes_signal_via_signal_handler(): void
    {
        $signalReceived = false;

        /** @var Behavior<TestMessage> */
        $behavior = Behavior::receive(
            static fn(ActorContext $ctx, object $msg): Behavior => Behavior::same(),
        )->onSignal(static function (ActorContext $ctx, Signal $signal) use (&$signalReceived): Behavior {
            if ($signal instanceof PreStart) {
                $signalReceived = true;
            }

            return Behavior::same();
        });

        $cell = $this->createCell($behavior);
        $cell->start();

        // PreStart is delivered during start()
        self::assertTrue($signalReceived);
    }

    // ======================================================================
    // Stateful Behavior Tests
    // ======================================================================

    #[Test]
    public function withState_tracks_state_across_messages(): void
    {
        /** @var Behavior<TestMessage> */
        $behavior = Behavior::withState(
            0,
            static fn(ActorContext $ctx, object $msg, int $state): BehaviorWithState => BehaviorWithState::next(
                $state + 1,
            ),
        );

        $cell = $this->createCell($behavior);
        $cell->start();

        // Send 3 messages — state should increment each time
        for ($i = 0; $i < 3; $i++) {
            $cell->processMessage(Envelope::of(
                new TestMessage("msg-{$i}"),
                ActorPath::root(),
                ActorPath::fromString('/user/test'),
            ));
        }

        // We can't easily inspect internal state directly, so we verify via a behavior
        // that tracks state. The cell should have state = 3 after 3 increments.
        // Instead, let's capture the state via a side-effect in the handler.
        $capturedStates = [];

        /** @var Behavior<TestMessage> */
        $statefulBehavior = Behavior::withState(
            0,
            static function (ActorContext $ctx, object $msg, int $state) use (&$capturedStates): BehaviorWithState {
                $capturedStates[] = $state;

                return BehaviorWithState::next($state + 1);
            },
        );

        $cell2 = $this->createCell($statefulBehavior);
        $cell2->start();

        for ($i = 0; $i < 3; $i++) {
            $cell2->processMessage(Envelope::of(
                new TestMessage("msg-{$i}"),
                ActorPath::root(),
                ActorPath::fromString('/user/test'),
            ));
        }

        self::assertSame([0, 1, 2], $capturedStates);
    }

    // ======================================================================
    // Stash Tests
    // ======================================================================

    #[Test]
    public function stash_saves_current_message(): void
    {
        $stashCalled = false;

        /** @var Behavior<TestMessage> */
        $behavior = Behavior::receive(
            static function (ActorContext $ctx, object $msg) use (&$stashCalled): Behavior {
                if ($msg instanceof TestMessage && $msg->value === 'stash-me') {
                    $ctx->stash();
                    $stashCalled = true;
                }

                return Behavior::same();
            },
        );

        $mailbox = TestMailbox::unbounded();
        $cell = $this->createCell($behavior, mailbox: $mailbox);
        $cell->start();

        $cell->processMessage(Envelope::of(
            new TestMessage('stash-me'),
            ActorPath::root(),
            ActorPath::fromString('/user/test'),
        ));

        self::assertTrue($stashCalled);
    }

    #[Test]
    public function unstashAll_prepends_stashed_to_mailbox(): void
    {
        $processOrder = [];

        /** @var Behavior<TestMessage> */
        $unstashBehavior = Behavior::receive(
            static function (ActorContext $ctx, object $msg) use (&$processOrder): Behavior {
                if ($msg instanceof TestMessage) {
                    $processOrder[] = $msg->value;
                }

                return Behavior::same();
            },
        );

        /** @var Behavior<TestMessage> */
        $stashBehavior = Behavior::receive(
            static function (ActorContext $ctx, object $msg) use ($unstashBehavior): Behavior {
                if ($msg instanceof TestMessage && $msg->value === 'trigger-unstash') {
                    $ctx->unstashAll();

                    return $unstashBehavior;
                }

                // Stash everything else
                $ctx->stash();

                return Behavior::same();
            },
        );

        $mailbox = TestMailbox::unbounded();
        $cell = $this->createCell($stashBehavior, mailbox: $mailbox);
        $cell->start();

        // Stash two messages
        $cell->processMessage(Envelope::of(
            new TestMessage('first'),
            ActorPath::root(),
            ActorPath::fromString('/user/test'),
        ));
        $cell->processMessage(Envelope::of(
            new TestMessage('second'),
            ActorPath::root(),
            ActorPath::fromString('/user/test'),
        ));

        // Trigger unstash — this should re-enqueue "first" and "second" back to mailbox
        $cell->processMessage(Envelope::of(
            new TestMessage('trigger-unstash'),
            ActorPath::root(),
            ActorPath::fromString('/user/test'),
        ));

        // Now the mailbox should have "first" and "second"
        self::assertSame(2, $mailbox->count());

        // Process them
        $env1 = $mailbox->dequeue()->get();
        $cell->processMessage($env1);
        $env2 = $mailbox->dequeue()->get();
        $cell->processMessage($env2);

        self::assertSame(['first', 'second'], $processOrder);
    }

    // ======================================================================
    // Child Management Tests
    // ======================================================================

    #[Test]
    public function spawn_creates_child_actor(): void
    {
        $childRef = null;

        /** @var Behavior<TestMessage> */
        $behavior = Behavior::receive(
            static function (ActorContext $ctx, object $msg) use (&$childRef): Behavior {
                if ($msg instanceof TestMessage && $msg->value === 'spawn') {
                    /** @var Behavior<TestMessage> */
                    $childBehavior = Behavior::receive(
                        static fn(ActorContext $c, object $m): Behavior => Behavior::same(),
                    );
                    $childRef = $ctx->spawn(Props::fromBehavior($childBehavior), 'child-1');
                }

                return Behavior::same();
            },
        );

        $cell = $this->createCell($behavior, ActorPath::fromString('/user/parent'));
        $cell->start();

        $cell->processMessage(Envelope::of(
            new TestMessage('spawn'),
            ActorPath::root(),
            ActorPath::fromString('/user/parent'),
        ));

        self::assertNotNull($childRef);
        self::assertTrue(ActorPath::fromString('/user/parent/child-1')->equals($childRef->path()));
        self::assertTrue($childRef->isAlive());
    }

    #[Test]
    public function children_returns_spawned_children(): void
    {
        /** @var Behavior<TestMessage> */
        $behavior = Behavior::receive(
            static function (ActorContext $ctx, object $msg): Behavior {
                if ($msg instanceof TestMessage && $msg->value === 'spawn') {
                    /** @var Behavior<TestMessage> */
                    $childBehavior = Behavior::receive(
                        static fn(ActorContext $c, object $m): Behavior => Behavior::same(),
                    );
                    $ctx->spawn(Props::fromBehavior($childBehavior), 'child-a');
                    $ctx->spawn(Props::fromBehavior($childBehavior), 'child-b');
                }

                return Behavior::same();
            },
        );

        $cell = $this->createCell($behavior, ActorPath::fromString('/user/parent'));
        $cell->start();

        $cell->processMessage(Envelope::of(
            new TestMessage('spawn'),
            ActorPath::root(),
            ActorPath::fromString('/user/parent'),
        ));

        $children = $cell->children();
        self::assertCount(2, $children);
    }

    #[Test]
    public function child_returns_named_child(): void
    {
        /** @var Behavior<TestMessage> */
        $behavior = Behavior::receive(
            static function (ActorContext $ctx, object $msg): Behavior {
                if ($msg instanceof TestMessage && $msg->value === 'spawn') {
                    /** @var Behavior<TestMessage> */
                    $childBehavior = Behavior::receive(
                        static fn(ActorContext $c, object $m): Behavior => Behavior::same(),
                    );
                    $ctx->spawn(Props::fromBehavior($childBehavior), 'worker');
                }

                return Behavior::same();
            },
        );

        $cell = $this->createCell($behavior, ActorPath::fromString('/user/parent'));
        $cell->start();

        $cell->processMessage(Envelope::of(
            new TestMessage('spawn'),
            ActorPath::root(),
            ActorPath::fromString('/user/parent'),
        ));

        $childOpt = $cell->child('worker');
        self::assertTrue($childOpt->isSome());
        self::assertTrue(
            ActorPath::fromString('/user/parent/worker')->equals($childOpt->get()->path()),
        );

        $noChild = $cell->child('nonexistent');
        self::assertTrue($noChild->isNone());
    }

    // ======================================================================
    // Context Tests
    // ======================================================================

    #[Test]
    public function self_returns_self_ref(): void
    {
        $behavior = Behavior::receive(
            static fn(ActorContext $ctx, object $msg): Behavior => Behavior::same(),
        );

        $cell = $this->createCell($behavior, ActorPath::fromString('/user/me'));
        $cell->start();

        $selfRef = $cell->self();
        self::assertTrue(ActorPath::fromString('/user/me')->equals($selfRef->path()));
        self::assertTrue($selfRef->isAlive());
    }

    #[Test]
    public function sender_returns_envelope_sender(): void
    {
        $capturedSender = null;

        /** @var Behavior<TestMessage> */
        $behavior = Behavior::receive(
            static function (ActorContext $ctx, object $msg) use (&$capturedSender): Behavior {
                $capturedSender = $ctx->sender();

                return Behavior::same();
            },
        );

        $cell = $this->createCell($behavior, ActorPath::fromString('/user/receiver'));
        $cell->start();

        $senderPath = ActorPath::fromString('/user/sender-actor');
        $cell->processMessage(Envelope::of(
            new TestMessage('hello'),
            $senderPath,
            ActorPath::fromString('/user/receiver'),
        ));

        self::assertNotNull($capturedSender);
        self::assertTrue($capturedSender->isSome());
    }

    #[Test]
    public function path_returns_actor_path(): void
    {
        $behavior = Behavior::receive(
            static fn(ActorContext $ctx, object $msg): Behavior => Behavior::same(),
        );

        $path = ActorPath::fromString('/user/my-actor');
        $cell = $this->createCell($behavior, $path);

        self::assertTrue($path->equals($cell->path()));
    }

    // ======================================================================
    // Setup Behavior Test
    // ======================================================================

    #[Test]
    public function setup_behavior_evaluates_factory_on_start(): void
    {
        $factoryCalled = false;
        $receivedContext = null;

        /** @var Behavior<TestMessage> */
        $behavior = Behavior::setup(
            static function (ActorContext $ctx) use (&$factoryCalled, &$receivedContext): Behavior {
                $factoryCalled = true;
                $receivedContext = $ctx;

                return Behavior::receive(
                    static fn(ActorContext $c, object $msg): Behavior => Behavior::same(),
                );
            },
        );

        $cell = $this->createCell($behavior, ActorPath::fromString('/user/setup-actor'));
        $cell->start();

        self::assertTrue($factoryCalled);
        self::assertNotNull($receivedContext);
        self::assertSame(ActorState::Running, $cell->actorState());
    }

    // ======================================================================
    // Composable Wrapper Resolution Tests
    // ======================================================================

    #[Test]
    public function withTimers_resolves_timer_scheduler_on_start(): void
    {
        $timerSchedulerReceived = null;

        /** @var Behavior<TestMessage> */
        $behavior = Behavior::withTimers(
            static function (TimerScheduler $timers) use (&$timerSchedulerReceived): Behavior {
                $timerSchedulerReceived = $timers;

                return Behavior::receive(
                    static fn(ActorContext $ctx, object $msg): Behavior => Behavior::same(),
                );
            },
        );

        $cell = $this->createCell($behavior);
        $cell->start();

        self::assertNotNull($timerSchedulerReceived);
        self::assertInstanceOf(TimerScheduler::class, $timerSchedulerReceived);
        self::assertSame(ActorState::Running, $cell->actorState());
    }

    #[Test]
    public function withStash_resolves_stash_buffer_on_start(): void
    {
        $stashBufferReceived = null;

        /** @var Behavior<TestMessage> */
        $behavior = Behavior::withStash(
            50,
            static function (StashBuffer $stash) use (&$stashBufferReceived): Behavior {
                $stashBufferReceived = $stash;

                return Behavior::receive(
                    static fn(ActorContext $ctx, object $msg): Behavior => Behavior::same(),
                );
            },
        );

        $cell = $this->createCell($behavior);
        $cell->start();

        self::assertNotNull($stashBufferReceived);
        self::assertInstanceOf(StashBuffer::class, $stashBufferReceived);
        self::assertSame(50, $stashBufferReceived->capacity());
        self::assertSame(ActorState::Running, $cell->actorState());
    }

    #[Test]
    public function supervised_resolves_inner_behavior_on_start(): void
    {
        $received = '';

        /** @var Behavior<TestMessage> */
        $inner = Behavior::receive(
            static function (ActorContext $ctx, object $msg) use (&$received): Behavior {
                if ($msg instanceof TestMessage) {
                    $received = $msg->value;
                }

                return Behavior::same();
            },
        );

        /** @var Behavior<TestMessage> */
        $behavior = Behavior::supervise($inner, SupervisionStrategy::oneForOne());

        $cell = $this->createCell($behavior);
        $cell->start();

        $cell->processMessage(Envelope::of(
            new TestMessage('supervised-msg'),
            ActorPath::root(),
            ActorPath::fromString('/user/test'),
        ));

        self::assertSame('supervised-msg', $received);
    }

    #[Test]
    public function nested_setup_with_timers_resolves(): void
    {
        $contextReceived = null;
        $timersReceived = null;

        /** @var Behavior<TestMessage> */
        $behavior = Behavior::setup(
            static function (ActorContext $ctx) use (&$contextReceived, &$timersReceived): Behavior {
                $contextReceived = $ctx;

                return Behavior::withTimers(
                    static function (TimerScheduler $timers) use (&$timersReceived): Behavior {
                        $timersReceived = $timers;

                        return Behavior::receive(
                            static fn(ActorContext $c, object $msg): Behavior => Behavior::same(),
                        );
                    },
                );
            },
        );

        $cell = $this->createCell($behavior);
        $cell->start();

        self::assertNotNull($contextReceived);
        self::assertNotNull($timersReceived);
        self::assertSame(ActorState::Running, $cell->actorState());
    }

    #[Test]
    public function full_composition_setup_timers_stash_supervise(): void
    {
        $allResolved = false;

        /** @var Behavior<TestMessage> */
        $behavior = Behavior::setup(
            static function (ActorContext $ctx) use (&$allResolved): Behavior {
                return Behavior::withTimers(
                    static function (TimerScheduler $timers) use (&$allResolved): Behavior {
                        return Behavior::withStash(
                            100,
                            static function (StashBuffer $stash) use (&$allResolved): Behavior {
                                $allResolved = true;

                                return Behavior::supervise(
                                    Behavior::receive(
                                        static fn(ActorContext $c, object $msg): Behavior => Behavior::same(),
                                    ),
                                    SupervisionStrategy::oneForOne(),
                                );
                            },
                        );
                    },
                );
            },
        );

        $cell = $this->createCell($behavior);
        $cell->start();

        self::assertTrue($allResolved);
        self::assertSame(ActorState::Running, $cell->actorState());
    }

    // ======================================================================
    // Inline Stash Replay Tests
    // ======================================================================

    #[Test]
    public function unstash_all_replays_messages_inline(): void
    {
        $processOrder = [];

        /** @var Behavior<TestMessage> $readyBehavior */
        $readyBehavior = Behavior::receive(
            static function (ActorContext $ctx, object $msg) use (&$processOrder): Behavior {
                if ($msg instanceof TestMessage) {
                    $processOrder[] = $msg->value;
                }

                return Behavior::same();
            },
        );

        $stashBuffer = null;

        /** @var Behavior<TestMessage> */
        $behavior = Behavior::withStash(
            100,
            static function (StashBuffer $stash) use (&$stashBuffer, $readyBehavior): Behavior {
                $stashBuffer = $stash;

                return Behavior::receive(
                    static function (ActorContext $ctx, object $msg) use ($stash, $readyBehavior): Behavior {
                        if ($msg instanceof TestMessage && $msg->value === 'go') {
                            return $stash->unstashAll($readyBehavior);
                        }

                        $stash->stash(Envelope::of(
                            $msg,
                            ActorPath::root(),
                            $ctx->path(),
                        ));

                        return Behavior::same();
                    },
                );
            },
        );

        $mailbox = TestMailbox::unbounded();
        $cell = $this->createCell($behavior, mailbox: $mailbox);
        $cell->start();

        // Stash two messages
        $cell->processMessage(Envelope::of(
            new TestMessage('first'),
            ActorPath::root(),
            ActorPath::fromString('/user/test'),
        ));
        $cell->processMessage(Envelope::of(
            new TestMessage('second'),
            ActorPath::root(),
            ActorPath::fromString('/user/test'),
        ));

        // Trigger unstash — should replay 'first' and 'second' inline
        $cell->processMessage(Envelope::of(
            new TestMessage('go'),
            ActorPath::root(),
            ActorPath::fromString('/user/test'),
        ));

        // Both stashed messages should have been replayed inline
        self::assertSame(['first', 'second'], $processOrder);
    }

    #[Override]
    protected function setUp(): void
    {
        $this->runtime = new TestRuntime();
        $this->deadLetters = new DeadLetterRef();
        $this->logger = new NullLogger();
    }

    // ---- Helpers ----

    /**
     * @template T of object
     * @param Behavior<T> $behavior
     * @return ActorCell<T>
     */
    private function createCell(Behavior $behavior, ?ActorPath $path = null, ?TestMailbox $mailbox = null): ActorCell
    {
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
}
