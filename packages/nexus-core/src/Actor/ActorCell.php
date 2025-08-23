<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

use Fp\Collections\HashMap;
use Fp\Functional\Option\Option;
use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Core\Exception\ActorInitializationException;
use Monadial\Nexus\Core\Exception\ActorNameExistsException;
use Monadial\Nexus\Core\Exception\InvalidActorStateTransition;
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
use Monadial\Nexus\Core\Message\Watch;
use Monadial\Nexus\Core\Message\Unwatch;
use Monadial\Nexus\Core\Runtime\Runtime;
use Monadial\Nexus\Core\Supervision\SupervisionStrategy;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
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

    /** @var HashMap<string, ActorRef<object>> */
    private HashMap $childrenMap;

    /** @var HashMap<string, ActorRef<object>> */
    private HashMap $watchers;

    /** @var list<Envelope> */
    private array $stashBuffer = [];

    private ?Envelope $currentEnvelope = null;

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

        /** @var HashMap<string, ActorRef<object>> $emptyChildren */
        $emptyChildren = HashMap::collect([]); // @phpstan-ignore varTag.type
        $this->childrenMap = $emptyChildren;

        /** @var HashMap<string, ActorRef<object>> $emptyWatchers */
        $emptyWatchers = HashMap::collect([]); // @phpstan-ignore varTag.type
        $this->watchers = $emptyWatchers;

        /** @var ActorRef<T> $ref */
        $ref = new LocalActorRef($this->actorPath, $this->mailbox, fn(): bool => $this->isAlive()); // @phpstan-ignore varTag.nativeType
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

        // Resolve setup behavior
        if ($this->currentBehavior->tag() === BehaviorTag::Setup) {
            $factory = $this->currentBehavior->handler()->get();
            \assert(\is_callable($factory));
            try {
                /** @var Behavior<T> $resolved */
                $resolved = $factory($this);
                $this->currentBehavior = $resolved;
            } catch (\Throwable $e) {
                $this->transitionTo(ActorState::Running);
                $this->transitionTo(ActorState::Stopping);
                $this->transitionTo(ActorState::Stopped);
                throw new ActorInitializationException($this->actorPath, $e->getMessage(), $e);
            }
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

        // Stop all children
        foreach ($this->childrenMap->values()->toList() as $child) {
            $child->tell(new PoisonPill());
        }

        // Deliver PostStop
        $this->handleSignal(new PostStop());

        $this->mailbox->close();

        $this->transitionTo(ActorState::Stopped);
    }

    // ---- ActorContext implementation ----

    /** @return ActorRef<T> */
    public function self(): ActorRef
    {
        return $this->selfRef;
    }

    /** @return Option<ActorRef<object>> */
    public function parent(): Option
    {
        return $this->parentRef;
    }

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
    public function spawn(Props $props, string $name): ActorRef
    {
        // Check for duplicate child name
        if ($this->childrenMap->get($name)->isSome()) { // @phpstan-ignore method.impossibleType
            throw new ActorNameExistsException($this->actorPath, $name);
        }

        $childPath = $this->actorPath->child($name);
        $childMailbox = $this->runtime->createMailbox($props->mailbox);

        $childSupervision = $props->supervision->isSome() // @phpstan-ignore method.impossibleType
            ? $props->supervision->get()
            : SupervisionStrategy::oneForOne();

        /** @var SupervisionStrategy $typedSupervision */
        $typedSupervision = $childSupervision;

        /** @var Option<ActorRef<object>> $parentOpt fp4php returns Option<ActorRef<T>>, widen to Option<ActorRef<object>> */
        $parentOpt = Option::some($this->selfRef); // @phpstan-ignore varTag.type

        /** @var ActorCell<C> $childCell */
        $childCell = new ActorCell(
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

        $childRef = $childCell->self();
        $this->childrenMap = $this->childrenMap->appended($name, $childRef);

        return $childRef;
    }

    /** @param ActorRef<object> $child */
    public function stop(ActorRef $child): void
    {
        $child->tell(new PoisonPill());
    }

    /** @return Option<ActorRef<object>> */
    public function child(string $name): Option
    {
        return $this->childrenMap->get($name);
    }

    /** @return HashMap<string, ActorRef<object>> */
    public function children(): HashMap
    {
        return $this->childrenMap;
    }

    /** @param ActorRef<object> $target */
    public function watch(ActorRef $target): void
    {
        $target->tell(new Watch($this->selfRef));
        $this->watchers = $this->watchers->appended((string) $target->path(), $target);
    }

    /** @param ActorRef<object> $target */
    public function unwatch(ActorRef $target): void
    {
        $target->tell(new Unwatch($this->selfRef));
        $this->watchers = $this->watchers->removed((string) $target->path());
    }

    /** @param T $message */
    public function scheduleOnce(Duration $delay, object $message): Cancellable
    {
        $selfRef = $this->selfRef;
        return $this->runtime->scheduleOnce($delay, static function () use ($selfRef, $message): void {
            $selfRef->tell($message);
        });
    }

    /** @param T $message */
    public function scheduleRepeatedly(Duration $initialDelay, Duration $interval, object $message): Cancellable
    {
        $selfRef = $this->selfRef;
        return $this->runtime->scheduleRepeatedly($initialDelay, $interval, static function () use ($selfRef, $message): void {
            $selfRef->tell($message);
        });
    }

    public function stash(): void
    {
        if ($this->currentEnvelope !== null) {
            $this->stashBuffer[] = $this->currentEnvelope;
        }
    }

    public function unstashAll(): void
    {
        // Re-enqueue all stashed messages to the mailbox
        foreach ($this->stashBuffer as $envelope) {
            try {
                $_ = $this->mailbox->enqueue($envelope);
            } catch (\Throwable) {
                // If mailbox is closed, we can't re-enqueue
                $this->logger->warning('Failed to unstash message to closed mailbox');
            }
        }
        $this->stashBuffer = [];
    }

    public function log(): LoggerInterface
    {
        return $this->logger;
    }

    /** @return Option<ActorRef<object>> */
    public function sender(): Option
    {
        if ($this->currentEnvelope === null) {
            /** @var Option<ActorRef<object>> $none */
            $none = Option::none(); // @phpstan-ignore varTag.type
            return $none;
        }

        $senderPath = $this->currentEnvelope->sender;

        // Root path means no sender
        if ($senderPath->equals(ActorPath::root())) {
            /** @var Option<ActorRef<object>> $none */
            $none = Option::none(); // @phpstan-ignore varTag.type
            return $none;
        }

        // Create a lightweight ref for the sender path
        // In a full system this would look up the actual ref; for now we create a ref
        $senderRef = new LocalActorRef(
            $senderPath,
            $this->mailbox, // placeholder - in full system would resolve actual mailbox
            static fn(): bool => true,
        );
        return Option::some($senderRef);
    }

    // ---- Internal message handling ----

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
            $this->watchers = $this->watchers->appended((string) $message->watcher->path(), $message->watcher);
        } elseif ($message instanceof Unwatch) {
            $this->watchers = $this->watchers->removed((string) $message->watcher->path());
        }
    }

    private function handleSignal(Signal $signal): void
    {
        $signalHandler = $this->currentBehavior->signalHandler();

        if ($signalHandler->isNone()) { // @phpstan-ignore method.impossibleType
            return;
        }

        $handler = $signalHandler->get();
        \assert(\is_callable($handler));

        try {
            /** @var Behavior<T> $result */
            $result = $handler($this, $signal);
            $this->applyBehavior($result);
        } catch (NexusException $e) {
            $this->logger->error('Signal handler threw NexusException: ' . $e->getMessage());
        } catch (\LogicException|\Error $e) {
            $this->logger->critical('Unchecked exception in signal handler: ' . $e->getMessage());
        } catch (\Throwable $e) {
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

        if ($handler->isNone()) { // @phpstan-ignore method.impossibleType
            // Empty or other non-receive behavior - route to dead letters
            $this->deadLetters->tell($message);
            return;
        }

        $fn = $handler->get();
        \assert(\is_callable($fn));

        try {
            /** @var Behavior<T> $result */
            $result = $fn($this, $message);
            $this->applyBehavior($result);
        } catch (NexusException $e) {
            $this->logger->error('Handler threw NexusException: ' . $e->getMessage());
            $this->supervision->decide($e);
        } catch (\LogicException|\Error $e) {
            $this->logger->critical('Unchecked exception in handler: ' . $e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->critical('Unexpected exception in handler: ' . $e->getMessage());
        }
    }

    private function handleStatefulMessage(object $message): void
    {
        $handler = $this->currentBehavior->handler();

        if ($handler->isNone()) { // @phpstan-ignore method.impossibleType
            $this->deadLetters->tell($message);
            return;
        }

        $fn = $handler->get();
        \assert(\is_callable($fn));

        try {
            /** @var BehaviorWithState<T, mixed> $result */
            $result = $fn($this, $message, $this->currentState);
            $this->applyStatefulBehavior($result);
        } catch (NexusException $e) {
            $this->logger->error('Stateful handler threw NexusException: ' . $e->getMessage());
            $this->supervision->decide($e);
        } catch (\LogicException|\Error $e) {
            $this->logger->critical('Unchecked exception in stateful handler: ' . $e->getMessage());
        } catch (\Throwable $e) {
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
        if ($result->state()->isSome()) { // @phpstan-ignore method.impossibleType
            $this->currentState = $result->state()->get();
        }

        // Swap behavior if provided
        if ($result->behavior()->isSome()) { // @phpstan-ignore method.impossibleType
            /** @var Behavior<T> $newBehavior */
            $newBehavior = $result->behavior()->get();
            $this->currentBehavior = $newBehavior;

            // If new behavior has initial state, use it instead
            if ($newBehavior->tag() === BehaviorTag::WithState) {
                $this->currentState = $newBehavior->initialState()->get();
            }
        }
    }

    private function transitionTo(ActorState $target): void
    {
        if (!$this->state->canTransitionTo($target)) {
            throw new InvalidActorStateTransition($this->state, $target);
        }
        $this->state = $target;
    }
}
