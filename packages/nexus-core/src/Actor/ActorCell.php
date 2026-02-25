<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

use Closure;
use Error;
use Fp\Functional\Option\Option;
use LogicException;
use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Core\Exception\ActorInitializationException;
use Monadial\Nexus\Core\Exception\ActorNameExistsException;
use Monadial\Nexus\Core\Exception\InvalidActorStateTransition;
use Monadial\Nexus\Core\Exception\MailboxClosedException;
use Monadial\Nexus\Core\Exception\MailboxTimeoutException;
use Monadial\Nexus\Core\Exception\NexusException;
use Monadial\Nexus\Core\Lifecycle\PostStop;
use Monadial\Nexus\Core\Lifecycle\PreStart;
use Monadial\Nexus\Core\Lifecycle\Signal;
use Monadial\Nexus\Core\Mailbox\Envelope;
use Monadial\Nexus\Core\Mailbox\Mailbox;
use Monadial\Nexus\Core\Message\PoisonPill;
use Monadial\Nexus\Core\Message\Resume;
use Monadial\Nexus\Core\Message\Suspend;
use Monadial\Nexus\Core\Message\SystemMessage;
use Monadial\Nexus\Core\Message\Unwatch;
use Monadial\Nexus\Core\Message\Watch;
use Monadial\Nexus\Core\Runtime\Runtime;
use Monadial\Nexus\Core\Supervision\Directive;
use Monadial\Nexus\Core\Supervision\SupervisionStrategy;
use Override;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Throwable;

use function assert;
use function is_callable;

/**
 * @psalm-api
 *
 * Internal engine of an actor. Manages behavior, state machine, children, stash, and supervision.
 *
 * @template T of object
 * @implements ActorContext<T>
 */
final class ActorCell implements ActorContext
{
    private ActorState $state = ActorState::New;

    /** @var Behavior<T> */
    private Behavior $currentBehavior;

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

    /**
     * @param Behavior<T> $behavior
     * @param Option<ActorRef<object>> $parentRef
     */
    public function __construct(
        Behavior $behavior,
        private readonly ActorPath $actorPath,
        private readonly Mailbox $mailbox,
        private readonly Runtime $runtime,
        private readonly Option $parentRef,
        private readonly SupervisionStrategy $supervision,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly DeadLetterRef $deadLetters,
    ) {
        $this->currentBehavior = $behavior;

        /** @var ActorRef<T> $ref */
        $ref = new LocalActorRef($this->actorPath, $this->mailbox, fn(): bool => $this->isAlive(), $this->runtime);
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

    /**
     * @throws ActorInitializationException
     */
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
        if ($this->currentBehavior->tag() === BehaviorTag::WithState) {
            $this->currentState = $this->currentBehavior->initialState()->get();
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
                $this->handleUserMessage($message);
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

        // Stop all children
        foreach ($this->childrenMap as $child) {
            $child->tell(new PoisonPill());
        }

        // Deliver PostStop
        $this->handleSignal(new PostStop());

        $this->mailbox->close();

        $this->transitionTo(ActorState::Stopped);
    }

    // ---- ActorContext implementation ----

    /** @return ActorRef<T> */
    #[Override]
    public function self(): ActorRef
    {
        return $this->selfRef;
    }

    /** @return Option<ActorRef<object>> */
    #[Override]
    public function parent(): Option
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
        $childMailbox = $this->runtime->createMailbox($props->mailbox);

        $childSupervision = $props->supervision->isSome()
            ? $props->supervision->get()
            : SupervisionStrategy::oneForOne();

        /** @var SupervisionStrategy $typedSupervision */
        $typedSupervision = $childSupervision;

        /** @var Option<ActorRef<object>> $parentOpt fp4php returns Option<ActorRef<T>>, widen to Option<ActorRef<object>> */
        $parentOpt = Option::some($this->selfRef);

        $childCell = new self(
            $props->behavior,
            $childPath,
            $childMailbox,
            $this->runtime,
            $parentOpt,
            $typedSupervision,
            $this->clock,
            $this->logger,
            $this->deadLetters,
        );
        $childCell->start();

        $this->spawnMessageLoop($childCell, $childMailbox);

        $childRef = $childCell->self();
        $this->childrenMap[$name] = $childRef;

        return $childRef;
    }

    /** @param ActorRef<object> $child */
    #[Override]
    public function stop(ActorRef $child): void
    {
        $child->tell(new PoisonPill());
    }

    /** @return Option<ActorRef<object>> */
    #[Override]
    public function child(string $name): Option
    {
        if (isset($this->childrenMap[$name])) {
            return Option::some($this->childrenMap[$name]);
        }

        /** @var Option<ActorRef<object>> $none */
        $none = Option::none();

        return $none;
    }

    /** @return array<string, ActorRef<object>> */
    #[Override]
    public function children(): array
    {
        return $this->childrenMap;
    }

    /** @param ActorRef<object> $target */
    #[Override]
    public function watch(ActorRef $target): void
    {
        $target->tell(new Watch($this->selfRef));
        $this->watchers[(string) $target->path()] = $target;
    }

    /** @param ActorRef<object> $target */
    #[Override]
    public function unwatch(ActorRef $target): void
    {
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

    /** @return Option<ActorRef<object>> */
    #[Override]
    public function sender(): Option
    {
        if ($this->currentEnvelope === null) {
            /** @var Option<ActorRef<object>> $none */
            $none = Option::none();

            return $none;
        }

        $senderPath = $this->currentEnvelope->sender;

        // Root path means no sender
        if ($senderPath->equals(ActorPath::root())) {
            /** @var Option<ActorRef<object>> $none */
            $none = Option::none();

            return $none;
        }

        // Create a lightweight ref for the sender path
        // In a full system this would look up the actual ref; for now we create a ref
        $senderRef = new LocalActorRef(
            $senderPath,
            $this->mailbox, // placeholder - in full system would resolve actual mailbox
            static fn(): bool => true,
            $this->runtime,
        );

        return Option::some($senderRef);
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
            $resolved = match ($this->currentBehavior->tag()) {
                BehaviorTag::Setup => $this->resolveSetup(),
                BehaviorTag::WithTimers => $this->resolveWithTimers(),
                BehaviorTag::WithStash => $this->resolveWithStash(),
                BehaviorTag::Supervised => $this->resolveSupervised(),
                default => null,
            };

            if ($resolved === null) {
                break;
            }

            $this->currentBehavior = $resolved;
            $depth++;
        }
    }

    /**
     * @return Behavior<T>
     */
    private function resolveSetup(): Behavior
    {
        $factory = $this->currentBehavior->handler()->get();
        assert(is_callable($factory));

        /** @var Behavior<T> */
        return $factory($this);
    }

    /**
     * @return Behavior<T>
     */
    private function resolveWithTimers(): Behavior
    {
        $factory = $this->currentBehavior->handler()->get();
        assert(is_callable($factory));

        $this->timerScheduler = new DefaultTimerScheduler($this->selfRef, $this->runtime);

        /** @var Behavior<T> */
        return $factory($this->timerScheduler);
    }

    /**
     * @return Behavior<T>
     */
    private function resolveWithStash(): Behavior
    {
        $factory = $this->currentBehavior->handler()->get();
        assert(is_callable($factory));

        /** @var int $capacity */
        $capacity = $this->currentBehavior->initialState()->get();

        /** @var DefaultStashBuffer<T> $stashBuffer */
        $stashBuffer = new DefaultStashBuffer($capacity);

        /** @var Behavior<T> */
        return $factory($stashBuffer);
    }

    /**
     * @return Behavior<T>
     */
    private function resolveSupervised(): Behavior
    {
        $innerProvider = $this->currentBehavior->handler()->get();
        assert(is_callable($innerProvider));

        /** @var SupervisionStrategy $strategy */
        $strategy = $this->currentBehavior->initialState()->get();
        $this->behaviorSupervision = $strategy;

        /** @var Behavior<T> */
        return $innerProvider();
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

    private function handleSignal(Signal $signal): void
    {
        $signalHandler = $this->currentBehavior->signalHandler();

        if ($signalHandler->isNone()) {
            return;
        }

        $handler = $signalHandler->get();

        try {
            /** @var Behavior<T> $result */
            $result = $handler($this, $signal);
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
        if ($this->currentBehavior->tag() === BehaviorTag::WithState) {
            $this->handleStatefulMessage($message);

            return;
        }

        $handler = $this->currentBehavior->handler();

        if ($handler->isNone()) {
            // Empty or other non-receive behavior - route to dead letters
            $this->deadLetters->tell($message);

            return;
        }

        $fn = $handler->get();

        try {
            /** @var Behavior<T> $result */
            $result = $fn($this, $message);
            $this->applyBehavior($result);
        } catch (NexusException $e) {
            $this->logger->error('Handler threw NexusException: ' . $e->getMessage());
            $this->decideSupervisedAction($e);
        } catch (Error|LogicException $e) {
            $this->logger->critical('Unchecked exception in handler: ' . $e->getMessage());
        } catch (Throwable $e) {
            $this->logger->critical('Unexpected exception in handler: ' . $e->getMessage());
        }
    }

    private function handleStatefulMessage(object $message): void
    {
        $handler = $this->currentBehavior->handler();

        if ($handler->isNone()) {
            $this->deadLetters->tell($message);

            return;
        }

        $fn = $handler->get();

        try {
            /** @var BehaviorWithState<T, mixed> $result */
            $result = $fn($this, $message, $this->currentState);
            $this->applyStatefulBehavior($result);
        } catch (NexusException $e) {
            $this->logger->error('Stateful handler threw NexusException: ' . $e->getMessage());
            $this->decideSupervisedAction($e);
        } catch (Error|LogicException $e) {
            $this->logger->critical('Unchecked exception in stateful handler: ' . $e->getMessage());
        } catch (Throwable $e) {
            $this->logger->critical('Unexpected exception in stateful handler: ' . $e->getMessage());
        }
    }

    /**
     * @param Behavior<T> $behavior
     */
    private function applyBehavior(Behavior $behavior): void
    {
        if ($behavior->isSame()) {
            return;
        }

        if ($behavior->isStopped()) {
            $this->initiateStop();

            return;
        }

        if ($behavior->isUnhandled()) {
            if ($this->currentEnvelope !== null) {
                $this->deadLetters->tell($this->currentEnvelope->message);
            }

            return;
        }

        // Handle inline stash replay
        if ($behavior->tag() === BehaviorTag::UnstashAll) {
            $this->handleUnstashAll($behavior);

            return;
        }

        // Behavior swap
        $this->currentBehavior = $behavior;

        // If new behavior is withState, initialize its state
        if ($behavior->tag() === BehaviorTag::WithState) {
            $this->currentState = $behavior->initialState()->get();
        }
    }

    /**
     * @param BehaviorWithState<T, mixed> $result
     */
    private function applyStatefulBehavior(BehaviorWithState $result): void
    {
        if ($result->isStopped()) {
            $this->initiateStop();

            return;
        }

        // Update state if provided
        if ($result->state()->isSome()) {
            $this->currentState = $result->state()->get();
        }

        // Swap behavior if provided
        if ($result->behavior()->isSome()) {
            $newBehavior = $result->behavior()->get();
            $this->currentBehavior = $newBehavior;

            // If new behavior has initial state, use it instead
            if ($newBehavior->tag() === BehaviorTag::WithState) {
                $this->currentState = $newBehavior->initialState()->get();
            }
        }
    }

    /**
     * @param Behavior<T> $unstashBehavior
     */
    private function handleUnstashAll(Behavior $unstashBehavior): void
    {
        $provider = $unstashBehavior->handler()->get();
        assert(is_callable($provider));

        /** @var array{envelopes: list<Envelope>, target: Behavior<T>} $payload */
        $payload = $provider();
        $envelopes = $payload['envelopes'];
        $target = $payload['target'];

        // Switch to target behavior first
        $this->currentBehavior = $target;

        if ($target->tag() === BehaviorTag::WithState) {
            $this->currentState = $target->initialState()->get();
        }

        // Replay each stashed envelope through the new behavior
        foreach ($envelopes as $envelope) {
            if (!$this->isAlive()) {
                break;
            }

            $this->processMessage($envelope);
        }
    }

    private function decideSupervisedAction(NexusException $e): void
    {
        if ($this->behaviorSupervision !== null) {
            $directive = $this->behaviorSupervision->decide($e);

            if ($directive !== Directive::Escalate) {
                return;
            }
        }

        $this->supervision->decide($e);
    }

    /**
     * Spawn a fiber that dequeues messages from the mailbox and processes them.
     *
     * @param ActorCell<object> $cell
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

        $this->state = $target;
    }
}
