<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

use Closure;
use DateTimeImmutable;
use Error;
use LogicException;
use Monadial\Nexus\Core\Exception\ActorInitializationException;
use Monadial\Nexus\Core\Exception\ActorNameExistsException;
use Monadial\Nexus\Core\Exception\InvalidActorStateTransition;
use Monadial\Nexus\Core\Exception\MaxRetriesExceededException;
use Monadial\Nexus\Core\Exception\NexusException;
use Monadial\Nexus\Core\Exception\NoSenderException;
use Monadial\Nexus\Core\Lifecycle\PostStop;
use Monadial\Nexus\Core\Lifecycle\PreRestart;
use Monadial\Nexus\Core\Lifecycle\PreStart;
use Monadial\Nexus\Core\Lifecycle\ReceiveTimeout;
use Monadial\Nexus\Core\Lifecycle\Signal;
use Monadial\Nexus\Core\Mailbox\Envelope;
use Monadial\Nexus\Core\Message\PoisonPill;
use Monadial\Nexus\Core\Message\Resume;
use Monadial\Nexus\Core\Message\Suspend;
use Monadial\Nexus\Core\Message\SystemMessage;
use Monadial\Nexus\Core\Message\Unwatch;
use Monadial\Nexus\Core\Message\Watch;
use Monadial\Nexus\Core\Supervision\Directive;
use Monadial\Nexus\Core\Supervision\SupervisionStrategy;
use Monadial\Nexus\Observability\Metric\Meter;
use Monadial\Nexus\Observability\Observability;
use Monadial\Nexus\Observability\Trace\NoopSpan;
use Monadial\Nexus\Observability\Trace\Span;
use Monadial\Nexus\Observability\Trace\SpanKind;
use Monadial\Nexus\Observability\Trace\StatusCode;
use Monadial\Nexus\Observability\Trace\Tracer;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Exception\MailboxClosedException;
use Monadial\Nexus\Runtime\Exception\MailboxTimeoutException;
use Monadial\Nexus\Runtime\Mailbox\Mailbox;
use Monadial\Nexus\Runtime\Runtime\Cancellable;
use Monadial\Nexus\Runtime\Runtime\Runtime;
use Override;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Throwable;

use function array_filter;
use function array_values;
use function assert;
use function count;
use function strrchr;
use function substr;

/**
 * @psalm-api
 *
 * Internal engine of an actor. Manages behavior, state machine, children, stash, and supervision.
 *
 * @internal Implementation detail of {@see ActorSystem::spawn()}. Not for direct use.
 *
 * @template T of object
 * @implements ActorContext<T>
 */
final class ActorCell implements ActorContext
{
    private ActorState $state = ActorState::New;

    /** @var Behavior<T> */
    private Behavior $currentBehavior;

    /**
     * Pristine behavior captured at construction. resolveWrappers() and behavior
     * swaps mutate {@see self::$currentBehavior}, losing the original — so a
     * supervised restart resets back to this untouched copy.
     *
     * @var Behavior<T>
     */
    private readonly Behavior $initialBehavior;

    /**
     * Timestamps of recent supervised restarts, used to enforce the retry cap
     * within {@see SupervisionStrategy::$window}. Older entries are pruned on
     * each restart attempt.
     *
     * @var list<DateTimeImmutable>
     */
    private array $restartLog = [];

    private mixed $currentState = null;

    /** @var ActorRef<T> */
    private ActorRef $selfRef;

    /** @var array<string, ActorRef<object>> */
    private array $childrenMap = [];

    /** @var array<string, ActorRef<object>> */
    private array $watchers = [];

    /** @var list<Envelope> */
    private array $stashBuffer = [];

    private ?Envelope $currentEnvelope = null;

    /** @var list<TaskContext> */
    private array $taskHandles = [];

    private ?TimerScheduler $timerScheduler = null;

    private ?SupervisionStrategy $behaviorSupervision = null;

    private ?Duration $receiveTimeout = null;

    private ?Cancellable $receiveTimer = null;

    private ?Span $currentSpan = null;

    /**
     * @param Behavior<T> $behavior
     * @param Mailbox<Envelope> $mailbox
     * @param ?ActorRef<object> $parentRef
     */
    public function __construct(
        Behavior $behavior,
        private readonly ActorPath $actorPath,
        private readonly Mailbox $mailbox,
        private readonly Runtime $runtime,
        private readonly ?ActorRef $parentRef,
        private readonly SupervisionStrategy $supervision,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly DeadLetterRef $deadLetters,
        private readonly Observability $observability,
    ) {
        $this->currentBehavior = $behavior;
        $this->initialBehavior = $behavior;

        /** @var ActorRef<T> $ref */
        $ref = new LocalActorRef(
            $this->actorPath,
            $this->mailbox,
            fn(): bool => $this->isAlive(),
            $this->runtime,
            $this->observability,
        );
        $this->selfRef = $ref;
    }

    // ---- State queries ----

    public function actorState(): ActorState
    {
        return $this->state;
    }

    public function isAlive(): bool
    {
        return $this->state !== ActorState::Stopped && $this->state !== ActorState::Stopping;
    }

    // ---- Lifecycle ----

    /** @throws ActorInitializationException */
    public function start(): void
    {
        $this->transitionTo(ActorState::Starting);

        try {
            $this->resolveWrappers();
        } catch (Throwable $e) {
            $this->transitionTo(ActorState::Running);
            $this->transitionTo(ActorState::Stopping);
            $this->transitionTo(ActorState::Stopped);

            throw new ActorInitializationException($this->actorPath, $e->getMessage(), $e);
        }

        // Handle initial state for withState behaviors
        if ($this->currentBehavior instanceof WithStateBehavior) {
            $this->currentState = $this->currentBehavior->initialState;
        }

        $this->transitionTo(ActorState::Running);

        // Deliver PreStart signal
        $this->handleSignal(new PreStart());
    }

    public function processMessage(Envelope $envelope): void
    {
        if ($this->state !== ActorState::Running) {
            return;
        }

        $this->currentEnvelope = $envelope;
        $message = $envelope->message;

        try {
            if ($message instanceof SystemMessage) {
                $this->handleSystemMessage($message);
            } elseif ($message instanceof Signal) {
                $this->handleSignal($message);
            } else {
                $this->resetReceiveTimer();
                $this->traceUserMessage($envelope, $message);
            }
        } finally {
            $this->currentEnvelope = null;
        }
    }

    public function initiateStop(): void
    {
        if ($this->state === ActorState::Stopped || $this->state === ActorState::Stopping) {
            return;
        }

        $this->transitionTo(ActorState::Stopping);

        // Cancel all spawned tasks
        foreach ($this->taskHandles as $handle) {
            $handle->cancel();
        }

        // Cancel all keyed timers
        if ($this->timerScheduler !== null) {
            $this->timerScheduler->cancelAll();
        }

        // Cancel the receive-timeout timer
        if ($this->receiveTimer !== null) {
            $this->receiveTimer->cancel();
            $this->receiveTimer = null;
        }

        // Stop all children
        foreach ($this->childrenMap as $child) {
            $child->tell(new PoisonPill());
        }

        // Deliver PostStop
        $this->handleSignal(new PostStop());

        $this->mailbox->close();

        $this->transitionTo(ActorState::Stopped);
    }

    /**
     * Supervised restart: reset the actor to its pristine behavior and re-run
     * startup, mirroring {@see self::start()}. Children are torn down, stash and
     * timers are cleared, and lifecycle signals are delivered (PreRestart on the
     * failed instance, PreStart on the fresh one — Akka semantics).
     */
    public function restart(Throwable $cause): void
    {
        $this->logger->debug('actor restarting', [
            'actor' => (string) $this->actorPath,
            'cause' => $cause->getMessage(),
        ]);

        // Deliver PreRestart to the current (failed) behavior before we discard
        // it. Best-effort: handleSignal already swallows signal-handler errors.
        $this->handleSignal(new PreRestart($cause));

        // Tear down children exactly like a stop would.
        foreach ($this->childrenMap as $child) {
            $child->tell(new PoisonPill());
        }

        $this->childrenMap = [];

        // Cancel spawned tasks and keyed timers from the previous incarnation.
        foreach ($this->taskHandles as $handle) {
            $handle->cancel();
        }

        $this->taskHandles = [];

        if ($this->timerScheduler !== null) {
            $this->timerScheduler->cancelAll();
            $this->timerScheduler = null;
        }

        // Drop buffered work and the pending receive-timeout timer.
        $this->stashBuffer = [];

        if ($this->receiveTimer !== null) {
            $this->receiveTimer->cancel();
            $this->receiveTimer = null;
        }

        $this->receiveTimeout = null;

        // Reset to the pristine behavior and re-resolve wrappers on a fresh
        // instance (Setup/WithTimers/WithStash/Supervised factories re-run).
        $this->currentBehavior = $this->initialBehavior;
        $this->currentState = null;
        $this->behaviorSupervision = null;

        $this->transitionTo(ActorState::Starting);

        try {
            $this->resolveWrappers();
        } catch (Throwable $e) {
            // A restart that cannot even re-initialize is unrecoverable; stop.
            $this->transitionTo(ActorState::Running);
            $this->logger->critical('Actor restart failed during setup: ' . $e->getMessage());
            $this->initiateStop();

            return;
        }

        // Re-seed initial state for stateful behaviors, mirroring start().
        if ($this->currentBehavior instanceof WithStateBehavior) {
            $this->currentState = $this->currentBehavior->initialState;
        }

        $this->transitionTo(ActorState::Running);

        // Deliver PreStart on the restarted instance.
        $this->handleSignal(new PreStart());
    }

    // ---- ActorContext implementation ----

    /** @return ActorRef<T> */
    #[Override]
    public function self(): ActorRef
    {
        return $this->selfRef;
    }

    /** @return ?ActorRef<object> */
    #[Override]
    public function parent(): ?ActorRef
    {
        return $this->parentRef;
    }

    #[Override]
    public function path(): ActorPath
    {
        return $this->actorPath;
    }

    /**
     * @template C of object
     * @param Props<C> $props
     * @return ActorRef<C>
     * @throws ActorInitializationException
     */
    #[Override]
    public function spawn(Props $props, string $name): ActorRef
    {
        // Check for duplicate child name
        if (isset($this->childrenMap[$name])) {
            throw new ActorNameExistsException($this->actorPath, $name);
        }

        $childPath = $this->actorPath->child($name);
        /** @var Mailbox<Envelope> $childMailbox */
        $childMailbox = $this->runtime->createMailbox($props->mailbox);

        $typedSupervision = $props->supervision ?? SupervisionStrategy::oneForOne();

        /** @var ActorRef<object> $parentRef */
        $parentRef = $this->selfRef;

        $childCell = new self(
            $props->behavior,
            $childPath,
            $childMailbox,
            $this->runtime,
            $parentRef,
            $typedSupervision,
            $this->clock,
            $this->logger,
            $this->deadLetters,
            $this->observability,
        );
        $childCell->start();

        $this->spawnMessageLoop($childCell, $childMailbox);

        $childRef = $childCell->self();
        $this->childrenMap[$name] = $childRef;

        return $childRef;
    }

    /**
     * @template W of object
     * @param ActorRef<W> $child
     */
    #[Override]
    public function stop(ActorRef $child): void
    {
        /** @psalm-suppress InvalidArgument — PoisonPill rides the user channel; the system-message path is type-erased by design. */
        $child->tell(new PoisonPill());
    }

    /** @return ?ActorRef<object> */
    #[Override]
    public function child(string $name): ?ActorRef
    {
        return $this->childrenMap[$name] ?? null;
    }

    /** @return array<string, ActorRef<object>> */
    #[Override]
    public function children(): array
    {
        return $this->childrenMap;
    }

    /**
     * @template W of object
     * @param ActorRef<W> $target
     */
    #[Override]
    public function watch(ActorRef $target): void
    {
        /** @psalm-suppress InvalidArgument — Watch rides the user channel; the system-message path is type-erased by design. */
        $target->tell(new Watch($this->selfRef));
        /** @psalm-suppress InvalidPropertyAssignmentValue — watchers is a heterogeneous identity map. */
        $this->watchers[(string) $target->path()] = $target;
    }

    /**
     * @template W of object
     * @param ActorRef<W> $target
     */
    #[Override]
    public function unwatch(ActorRef $target): void
    {
        /** @psalm-suppress InvalidArgument — Unwatch rides the user channel; the system-message path is type-erased by design. */
        $target->tell(new Unwatch($this->selfRef));
        unset($this->watchers[(string) $target->path()]);
    }

    /** @param T $message */
    #[Override]
    public function scheduleOnce(Duration $delay, object $message): Cancellable
    {
        $selfRef = $this->selfRef;

        return $this->runtime->scheduleOnce($delay, static function () use ($selfRef, $message): void {
            $selfRef->tell($message);
        });
    }

    /** @param T $message */
    #[Override]
    public function scheduleRepeatedly(Duration $initialDelay, Duration $interval, object $message): Cancellable
    {
        $selfRef = $this->selfRef;

        return $this->runtime->scheduleRepeatedly(
            $initialDelay,
            $interval,
            static function () use ($selfRef, $message): void {
                $selfRef->tell($message);
            },
        );
    }

    #[Override]
    public function stash(): void
    {
        if ($this->currentEnvelope !== null) {
            $this->stashBuffer[] = $this->currentEnvelope;
        }
    }

    #[Override]
    public function unstashAll(): void
    {
        // Re-enqueue all stashed messages to the mailbox
        foreach ($this->stashBuffer as $envelope) {
            try {
                $_ = $this->mailbox->enqueue($envelope);
            } catch (Throwable) {
                // If mailbox is closed, we can't re-enqueue
                $this->logger->warning('Failed to unstash message to closed mailbox');
            }
        }

        $this->stashBuffer = [];
    }

    #[Override]
    public function log(): LoggerInterface
    {
        return $this->logger;
    }

    #[Override]
    public function tracer(): Tracer
    {
        return $this->observability->tracer();
    }

    #[Override]
    public function meter(): Meter
    {
        return $this->observability->meter();
    }

    #[Override]
    public function currentSpan(): Span
    {
        return $this->currentSpan ?? new NoopSpan();
    }

    /** @return ?ActorRef<object> */
    #[Override]
    public function sender(): ?ActorRef
    {
        if ($this->currentEnvelope === null) {
            return null;
        }

        // If the envelope carries a direct ref (from ask()), use it
        if ($this->currentEnvelope->senderRef !== null) {
            return $this->currentEnvelope->senderRef;
        }

        $senderPath = $this->currentEnvelope->sender;

        // Root path means no sender
        if ($senderPath->equals(ActorPath::root())) {
            return null;
        }

        // Path-based reconstruction for cluster/remote senders
        return new LocalActorRef(
            $senderPath,
            $this->mailbox, // placeholder — in full system would resolve actual mailbox
            static fn(): bool => true,
            $this->runtime,
            $this->observability,
        );
    }

    #[Override]
    public function reply(object $message): void
    {
        $sender = $this->sender();

        if ($sender === null) {
            throw new NoSenderException('Cannot reply: no sender on current message');
        }

        $sender->tell($message);
    }

    #[Override]
    public function setReceiveTimeout(?Duration $timeout): void
    {
        $this->receiveTimeout = $timeout;

        if ($this->receiveTimer !== null) {
            $this->receiveTimer->cancel();
            $this->receiveTimer = null;
        }

        if ($timeout === null) {
            return;
        }

        $this->armReceiveTimer($timeout);
    }

    /** @param Closure(TaskContext): void $task */
    #[Override]
    public function spawnTask(Closure $task): Cancellable
    {
        $taskContext = new TaskContext($this->selfRef, $this->logger);
        $this->taskHandles[] = $taskContext;

        $this->runtime->spawn(static function () use ($task, $taskContext): void {
            try {
                $task($taskContext);
            } catch (Throwable $e) {
                $taskContext->log()->error('Spawned task threw exception: ' . $e->getMessage());
            }
        });

        return $taskContext;
    }

    // ---- Internal message handling ----

    /**
     * Recursively resolve wrapper behaviors (Setup, WithTimers, WithStash, Supervised).
     */
    private function resolveWrappers(): void
    {
        $maxDepth = 10;
        $depth = 0;

        while ($depth < $maxDepth) {
            if ($this->currentBehavior instanceof SetupBehavior) {
                $resolved = $this->resolveSetup();
            } elseif ($this->currentBehavior instanceof WithTimersBehavior) {
                $resolved = $this->resolveWithTimers();
            } elseif ($this->currentBehavior instanceof WithStashBehavior) {
                $resolved = $this->resolveWithStash();
            } elseif ($this->currentBehavior instanceof SupervisedBehavior) {
                $resolved = $this->resolveSupervised();
            } else {
                $resolved = null;
            }

            if ($resolved === null) {
                break;
            }

            $this->currentBehavior = $resolved;
            $depth++;
        }
    }

    /** @return Behavior<T> */
    private function resolveSetup(): Behavior
    {
        assert($this->currentBehavior instanceof SetupBehavior);

        /** @var Behavior<T> */
        return ($this->currentBehavior->factory)($this);
    }

    /** @return Behavior<T> */
    private function resolveWithTimers(): Behavior
    {
        assert($this->currentBehavior instanceof WithTimersBehavior);

        $this->timerScheduler = new DefaultTimerScheduler($this->selfRef, $this->runtime);

        /** @var Behavior<T> */
        return ($this->currentBehavior->factory)($this->timerScheduler);
    }

    /** @return Behavior<T> */
    private function resolveWithStash(): Behavior
    {
        assert($this->currentBehavior instanceof WithStashBehavior);

        /** @var DefaultStashBuffer<T> $stashBuffer */
        $stashBuffer = new DefaultStashBuffer($this->currentBehavior->capacity);

        /** @var Behavior<T> */
        return ($this->currentBehavior->factory)($stashBuffer);
    }

    /** @return Behavior<T> */
    private function resolveSupervised(): Behavior
    {
        assert($this->currentBehavior instanceof SupervisedBehavior);

        $this->behaviorSupervision = $this->currentBehavior->strategy;

        /** @var Behavior<T> */
        return $this->currentBehavior->inner;
    }

    private function handleSystemMessage(SystemMessage $message): void
    {
        if ($message instanceof PoisonPill) {
            $this->initiateStop();
        } elseif ($message instanceof Suspend) {
            if ($this->state->canTransitionTo(ActorState::Suspended)) {
                $this->transitionTo(ActorState::Suspended);
            }
        } elseif ($message instanceof Resume) {
            if ($this->state->canTransitionTo(ActorState::Running)) {
                $this->transitionTo(ActorState::Running);
            }
        } elseif ($message instanceof Watch) {
            $this->watchers[(string) $message->watcher->path()] = $message->watcher;
        } elseif ($message instanceof Unwatch) {
            unset($this->watchers[(string) $message->watcher->path()]);
        }
    }

    private function resetReceiveTimer(): void
    {
        $timeout = $this->receiveTimeout;

        if ($timeout === null) {
            return;
        }

        if ($this->receiveTimer !== null) {
            $this->receiveTimer->cancel();
        }

        $this->armReceiveTimer($timeout);
    }

    private function armReceiveTimer(Duration $timeout): void
    {
        $self = $this;
        $this->receiveTimer = $this->runtime->scheduleOnce(
            $timeout,
            static function () use ($self, $timeout): void {
                $self->onReceiveTimeoutFired($timeout);
            },
        );
    }

    private function onReceiveTimeoutFired(Duration $configured): void
    {
        if ($this->receiveTimer === null) {
            return;
        }

        $this->receiveTimer = null;
        $this->handleSignal(new ReceiveTimeout($configured));
    }

    private function handleSignal(Signal $signal): void
    {
        $this->logger->debug('actor signal', [
            'actor' => (string) $this->actorPath,
            'signal' => $this->messageType($signal),
        ]);

        $signalHandler = $this->currentBehavior->signalHandler();

        if ($signalHandler === null) {
            return;
        }

        try {
            $result = $signalHandler($this, $signal);
            $this->applyBehavior($result);
        } catch (NexusException $e) {
            $this->logger->error('Signal handler threw NexusException: ' . $e->getMessage());
        } catch (Error|LogicException $e) {
            $this->logger->critical('Unchecked exception in signal handler: ' . $e->getMessage());
        } catch (Throwable $e) {
            $this->logger->critical('Unexpected exception in signal handler: ' . $e->getMessage());
        }
    }

    private function handleUserMessage(object $message): void
    {
        if ($this->currentBehavior instanceof WithStateBehavior) {
            $this->handleStatefulMessage($message);

            return;
        }

        if (!($this->currentBehavior instanceof ReceiveBehavior)) {
            // Empty or other non-receive behavior - route to dead letters
            $this->deadLetters->tell($message);

            return;
        }

        try {
            /** @var Behavior<T> $result */
            $result = ($this->currentBehavior->handler)($this, $message);
            $this->applyBehavior($result);
        } catch (NexusException $e) {
            $this->logger->error('Handler threw NexusException: ' . $e->getMessage());
            $this->currentSpan?->recordException($e);
            $this->currentSpan?->setStatus(StatusCode::Error, $e->getMessage());
            $this->decideSupervisedAction($e);
        } catch (Error|LogicException $e) {
            $this->logger->critical('Unchecked exception in handler: ' . $e->getMessage());
            $this->currentSpan?->recordException($e);
            $this->currentSpan?->setStatus(StatusCode::Error, $e->getMessage());
            $this->decideSupervisedAction($e);
        } catch (Throwable $e) {
            $this->logger->critical('Unexpected exception in handler: ' . $e->getMessage());
            $this->currentSpan?->recordException($e);
            $this->currentSpan?->setStatus(StatusCode::Error, $e->getMessage());
            $this->decideSupervisedAction($e);
        }
    }

    private function handleStatefulMessage(object $message): void
    {
        assert($this->currentBehavior instanceof WithStateBehavior);

        try {
            /** @var BehaviorWithState<T, mixed> $result */
            $result = ($this->currentBehavior->handler)($this, $message, $this->currentState);
            $this->applyStatefulBehavior($result);
        } catch (NexusException $e) {
            $this->logger->error('Stateful handler threw NexusException: ' . $e->getMessage());
            $this->currentSpan?->recordException($e);
            $this->currentSpan?->setStatus(StatusCode::Error, $e->getMessage());
            $this->decideSupervisedAction($e);
        } catch (Error|LogicException $e) {
            $this->logger->critical('Unchecked exception in stateful handler: ' . $e->getMessage());
            $this->currentSpan?->recordException($e);
            $this->currentSpan?->setStatus(StatusCode::Error, $e->getMessage());
            $this->decideSupervisedAction($e);
        } catch (Throwable $e) {
            $this->logger->critical('Unexpected exception in stateful handler: ' . $e->getMessage());
            $this->currentSpan?->recordException($e);
            $this->currentSpan?->setStatus(StatusCode::Error, $e->getMessage());
            $this->decideSupervisedAction($e);
        }
    }

    /** @param Behavior<T> $behavior */
    private function applyBehavior(Behavior $behavior): void
    {
        if ($behavior instanceof SameBehavior) {
            return;
        }

        if ($behavior instanceof StoppedBehavior) {
            $this->initiateStop();

            return;
        }

        if ($behavior instanceof UnhandledBehavior) {
            if ($this->currentEnvelope !== null) {
                $this->deadLetters->tell($this->currentEnvelope->message);
            }

            return;
        }

        // Handle inline stash replay
        if ($behavior instanceof UnstashAllBehavior) {
            /** @var UnstashAllBehavior<T> $behavior */
            $this->handleUnstashAll($behavior);

            return;
        }

        // Behavior swap
        $this->currentBehavior = $behavior;

        // If new behavior is withState, initialize its state
        if ($behavior instanceof WithStateBehavior) {
            $this->currentState = $behavior->initialState;
        }
    }

    /** @param BehaviorWithState<T, mixed> $result */
    private function applyStatefulBehavior(BehaviorWithState $result): void
    {
        if ($result->isStopped()) {
            $this->initiateStop();

            return;
        }

        // Update state if provided
        if ($result->hasNewState()) {
            $this->currentState = $result->state();
        }

        // Swap behavior if provided
        if ($result->behavior() !== null) {
            $newBehavior = $result->behavior();
            $this->currentBehavior = $newBehavior;

            // If new behavior has initial state, use it instead
            if ($newBehavior instanceof WithStateBehavior) {
                $this->currentState = $newBehavior->initialState;
            }
        }
    }

    /** @param UnstashAllBehavior<T> $unstashBehavior */
    private function handleUnstashAll(UnstashAllBehavior $unstashBehavior): void
    {
        $target = $unstashBehavior->target;

        // Switch to target behavior first
        $this->currentBehavior = $target;

        if ($target instanceof WithStateBehavior) {
            $this->currentState = $target->initialState;
        }

        // Replay each stashed envelope through the new behavior
        foreach ($unstashBehavior->envelopes as $envelope) {
            if (!$this->isAlive()) {
                break;
            }

            $this->processMessage($envelope);
        }
    }

    private function traceUserMessage(Envelope $envelope, object $message): void
    {
        if (!$this->observability->isEnabled()) {
            $this->handleUserMessage($message);

            return;
        }

        $span = null;
        $start = null;

        try {
            $type = $this->messageType($message);
            $parent = $this->observability->propagator()->extract($envelope->metadata);
            $span = $this->observability->tracer()->startSpan(
                'process ' . $type,
                SpanKind::Consumer,
                [
                    'messaging.operation' => 'process',
                    'messaging.system' => 'nexus',
                    'nexus.actor.path' => (string) $this->actorPath,
                    'nexus.mailbox.depth' => $this->mailbox->count(),
                    'nexus.message.type' => $type,
                ],
                $parent,
            );
            $this->currentSpan = $span;
            $start = $this->clock->now();
        } catch (Throwable $e) {
            $this->logger->warning('Observability span start failed: ' . $e->getMessage());
            $this->currentSpan = null;
        }

        try {
            $this->handleUserMessage($message);
        } finally {
            try {
                if ($span !== null && $start !== null) {
                    $this->recordProcessingMetrics($this->messageType($message), $start);
                    $span->end();
                }
            } catch (Throwable $e) {
                $this->logger->warning('Observability span end failed: ' . $e->getMessage());
            }

            $this->currentSpan = null;
        }
    }

    /**
     * @psalm-suppress InvalidOperand
     */
    private function recordProcessingMetrics(string $type, DateTimeImmutable $start): void
    {
        $meter = $this->observability->meter();
        $meter->counter('nexus.actor.messages.processed', '{message}', 'User messages processed by actors')
            ->add(1, ['nexus.message.type' => $type]);

        $durationMs = ((float) $this->clock->now()->format('U.u') - (float) $start->format('U.u')) * 1000.0;
        $meter->histogram('nexus.actor.message.processing.duration', 'ms', 'Actor message processing duration')
            ->record($durationMs, ['nexus.message.type' => $type]);
    }

    private function messageType(object $message): string
    {
        $class = $message::class;
        $pos = strrchr($class, '\\');

        return $pos === false
            ? $class
            : substr($pos, 1);
    }

    /**
     * Compute and ENACT the supervision directive for a handler failure.
     *
     * Behavior-level supervision (from Behavior::supervise) takes precedence; a
     * non-Escalate directive it yields wins, otherwise we fall back to the
     * props-level strategy supplied at spawn.
     */
    private function decideSupervisedAction(Throwable $cause): void
    {
        if ($this->behaviorSupervision !== null) {
            $behaviorDirective = $this->behaviorSupervision->decide($cause);

            if ($behaviorDirective !== Directive::Escalate) {
                $this->applyDirective($behaviorDirective, $this->behaviorSupervision, $cause);

                return;
            }
        }

        $this->applyDirective($this->supervision->decide($cause), $this->supervision, $cause);
    }

    private function applyDirective(Directive $directive, SupervisionStrategy $strategy, Throwable $cause): void
    {
        $this->logger->debug('actor supervision directive', [
            'actor' => (string) $this->actorPath,
            'cause' => $cause->getMessage(),
            'directive' => $directive->name,
        ]);

        match ($directive) {
            // Keep behavior + state intact. If suspended by a prior failure,
            // resume message processing.
            Directive::Resume => $this->resumeAfterFailure(),
            Directive::Restart => $this->restartWithinLimits($strategy, $cause),
            Directive::Stop => $this->initiateStop(),
            // Escalation-to-parent is not yet wired (there is no ChildFailed
            // channel toward the parent). Fail safe by stopping for now.
            Directive::Escalate => $this->escalateAsStop(),
        };
    }

    private function resumeAfterFailure(): void
    {
        if ($this->state === ActorState::Suspended) {
            $this->transitionTo(ActorState::Running);
        }
    }

    private function escalateAsStop(): void
    {
        $this->logger->warning(
            'Supervision escalation is not wired to the parent; stopping actor ' . (string) $this->actorPath,
        );
        $this->initiateStop();
    }

    /**
     * Restart the actor, honoring the retry cap: if the number of restarts
     * within {@see SupervisionStrategy::$window} reaches maxRetries, stop the
     * actor instead of looping forever.
     */
    private function restartWithinLimits(SupervisionStrategy $strategy, Throwable $cause): void
    {
        $now = $this->clock->now();

        // Prune restarts that fell outside the sliding window so the cap only
        // counts recent failures. A zero window (e.g. exponentialBackoff) has no
        // expiry, so the cap applies across the actor's whole lifetime.
        if ($strategy->window->toNanos() > 0) {
            $windowMicros = (int) ($strategy->window->toNanos() / 1000);
            $cutoff = $now->modify("-{$windowMicros} microseconds");
            $this->restartLog = array_values(array_filter(
                $this->restartLog,
                static fn(DateTimeImmutable $timestamp): bool => $timestamp > $cutoff,
            ));
        }

        if (count($this->restartLog) >= $strategy->maxRetries) {
            $this->logger->error((string) new MaxRetriesExceededException(
                $this->actorPath,
                $strategy->maxRetries,
                $strategy->window,
                $cause,
            ));
            $this->initiateStop();

            return;
        }

        $this->restartLog[] = $now;
        $this->restart($cause);
    }

    /**
     * Spawn a fiber that dequeues messages from the mailbox and processes them.
     *
     * @param ActorCell<object> $cell
     * @param Mailbox<Envelope> $mailbox
     */
    private function spawnMessageLoop(self $cell, Mailbox $mailbox): void
    {
        $this->runtime->spawn(static function () use ($cell, $mailbox): void {
            while ($cell->isAlive()) {
                try {
                    $envelope = $mailbox->dequeueBlocking(Duration::seconds(1));
                    $cell->processMessage($envelope);
                } catch (MailboxTimeoutException) {
                    continue;
                } catch (MailboxClosedException) {
                    break;
                }
            }
        });
    }

    private function transitionTo(ActorState $target): void
    {
        if (!$this->state->canTransitionTo($target)) {
            throw new InvalidActorStateTransition($this->state, $target);
        }

        $this->logger->debug('actor state transition', [
            'actor' => (string) $this->actorPath,
            'from' => $this->state->name,
            'to' => $target->name,
        ]);
        $this->state = $target;
    }
}
