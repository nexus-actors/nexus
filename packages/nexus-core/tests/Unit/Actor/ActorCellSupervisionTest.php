<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Unit\Actor;

use Monadial\Nexus\Core\Actor\ActorCell;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\ActorState;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\BehaviorWithState;
use Monadial\Nexus\Core\Actor\DeadLetterRef;
use Monadial\Nexus\Core\Lifecycle\PreRestart;
use Monadial\Nexus\Core\Lifecycle\Signal;
use Monadial\Nexus\Core\Mailbox\Envelope;
use Monadial\Nexus\Core\Supervision\Directive;
use Monadial\Nexus\Core\Supervision\SupervisionStrategy;
use Monadial\Nexus\Core\Tests\Support\TestLogger;
use Monadial\Nexus\Core\Tests\Support\TestMailbox;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Monadial\Nexus\Observability\NoopObservability;
use Monadial\Nexus\Runtime\Duration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(ActorCell::class)]
final class ActorCellSupervisionTest extends TestCase
{
    private TestRuntime $runtime;
    private DeadLetterRef $deadLetters;
    private TestLogger $logger;

    #[Test]
    public function restart_resets_stateful_actor_state_to_initial(): void
    {
        $observed = null;

        /** @var Behavior<SupervisionTestMessage> */
        $behavior = Behavior::withState(
            0,
            static function (ActorContext $ctx, object $msg, int $count) use (&$observed): BehaviorWithState {
                if ($msg instanceof SupervisionTestMessage && $msg->kind === 'inc') {
                    return BehaviorWithState::next($count + 1);
                }

                if ($msg instanceof SupervisionTestMessage && $msg->kind === 'get') {
                    $observed = $count;

                    return BehaviorWithState::same();
                }

                throw new RuntimeException('boom');
            },
        );

        $cell = $this->createCell($behavior, SupervisionStrategy::oneForOne());
        $cell->start();

        $cell->processMessage($this->envelope(new SupervisionTestMessage('inc')));
        $cell->processMessage($this->envelope(new SupervisionTestMessage('inc')));
        $cell->processMessage($this->envelope(new SupervisionTestMessage('get')));
        self::assertSame(2, $observed, 'State should have advanced to 2 before the failure');

        // Trigger a failure: default supervision restarts the actor.
        $cell->processMessage($this->envelope(new SupervisionTestMessage('boom')));
        self::assertSame(ActorState::Running, $cell->actorState());

        // After restart, state must be reset to the behavior's initialState (0).
        $cell->processMessage($this->envelope(new SupervisionTestMessage('get')));
        self::assertSame(0, $observed, 'State should reset to initialState after restart');
    }

    #[Test]
    public function stop_directive_stops_actor_on_failure(): void
    {
        /** @var Behavior<SupervisionTestMessage> */
        $behavior = Behavior::receive(
            static function (ActorContext $ctx, object $msg): Behavior {
                throw new RuntimeException('boom');
            },
        );

        $strategy = SupervisionStrategy::oneForOne(
            decider: static fn(): Directive => Directive::Stop,
        );

        $cell = $this->createCell($behavior, $strategy);
        $cell->start();

        $cell->processMessage($this->envelope(new SupervisionTestMessage('boom')));

        self::assertSame(ActorState::Stopped, $cell->actorState());
    }

    #[Test]
    public function resume_directive_keeps_state_intact_on_failure(): void
    {
        $observed = null;

        /** @var Behavior<SupervisionTestMessage> */
        $behavior = Behavior::withState(
            0,
            static function (ActorContext $ctx, object $msg, int $count) use (&$observed): BehaviorWithState {
                if ($msg instanceof SupervisionTestMessage && $msg->kind === 'inc') {
                    return BehaviorWithState::next($count + 1);
                }

                if ($msg instanceof SupervisionTestMessage && $msg->kind === 'get') {
                    $observed = $count;

                    return BehaviorWithState::same();
                }

                throw new RuntimeException('boom');
            },
        );

        $strategy = SupervisionStrategy::oneForOne(
            decider: static fn(): Directive => Directive::Resume,
        );

        $cell = $this->createCell($behavior, $strategy);
        $cell->start();

        $cell->processMessage($this->envelope(new SupervisionTestMessage('inc')));
        $cell->processMessage($this->envelope(new SupervisionTestMessage('inc')));

        // Failure with Resume must NOT reset state.
        $cell->processMessage($this->envelope(new SupervisionTestMessage('boom')));
        self::assertSame(ActorState::Running, $cell->actorState());

        $cell->processMessage($this->envelope(new SupervisionTestMessage('get')));
        self::assertSame(2, $observed, 'State should be preserved after Resume');
    }

    #[Test]
    public function exceeding_max_retries_within_window_stops_actor(): void
    {
        /** @var Behavior<SupervisionTestMessage> */
        $behavior = Behavior::receive(
            static function (ActorContext $ctx, object $msg): Behavior {
                throw new RuntimeException('boom');
            },
        );

        // Allow 2 restarts within the window; the 3rd failure exceeds the cap.
        $strategy = SupervisionStrategy::oneForOne(maxRetries: 2, window: Duration::seconds(60));

        $cell = $this->createCell($behavior, $strategy);
        $cell->start();

        // Restart #1 and #2 keep the actor Running.
        $cell->processMessage($this->envelope(new SupervisionTestMessage('boom')));
        self::assertSame(ActorState::Running, $cell->actorState());

        $cell->processMessage($this->envelope(new SupervisionTestMessage('boom')));
        self::assertSame(ActorState::Running, $cell->actorState());

        // Third failure exceeds maxRetries -> the actor is stopped instead of looping.
        $cell->processMessage($this->envelope(new SupervisionTestMessage('boom')));
        self::assertSame(ActorState::Stopped, $cell->actorState());

        self::assertTrue(
            $this->logger->hasLogMatching('error', 'exceeded max retries'),
            'Expected MaxRetriesExceeded error log; got: ' . print_r($this->logger->logs, true),
        );
    }

    #[Test]
    public function preRestart_signal_is_delivered_on_restart(): void
    {
        $restartCause = null;

        /** @var Behavior<SupervisionTestMessage> */
        $behavior = Behavior::receive(
            static function (ActorContext $ctx, object $msg): Behavior {
                throw new RuntimeException('boom');
            },
        )->onSignal(
            static function (ActorContext $ctx, Signal $signal) use (&$restartCause): Behavior {
                if ($signal instanceof PreRestart) {
                    $restartCause = $signal->cause->getMessage();
                }

                return Behavior::same();
            },
        );

        $cell = $this->createCell($behavior, SupervisionStrategy::oneForOne());
        $cell->start();

        $cell->processMessage($this->envelope(new SupervisionTestMessage('boom')));

        self::assertSame('boom', $restartCause, 'PreRestart should carry the failure cause');
        self::assertSame(ActorState::Running, $cell->actorState());
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
    private function createCell(Behavior $behavior, SupervisionStrategy $supervision): ActorCell
    {
        return new ActorCell(
            $behavior,
            ActorPath::fromString('/user/test'),
            TestMailbox::unbounded(),
            $this->runtime,
            null,
            $supervision,
            $this->runtime->clock(),
            $this->logger,
            $this->deadLetters,
            new NoopObservability(),
        );
    }

    private function envelope(object $message): Envelope
    {
        return Envelope::of(
            $message,
            ActorPath::root(),
            ActorPath::fromString('/user/test'),
        );
    }
}

final readonly class SupervisionTestMessage
{
    public function __construct(public string $kind) {}
}
