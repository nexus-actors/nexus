<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

use DateTimeImmutable;
use Monadial\Nexus\Core\Exception\ActorInitializationException;
use Monadial\Nexus\Core\Exception\ActorNameExistsException;
use Monadial\Nexus\Core\Mailbox\Envelope;
use Monadial\Nexus\Core\Message\PoisonPill;
use Monadial\Nexus\Core\Supervision\SupervisionStrategy;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Exception\MailboxClosedException;
use Monadial\Nexus\Runtime\Exception\MailboxTimeoutException;
use Monadial\Nexus\Runtime\Mailbox\Mailbox;
use Monadial\Nexus\Runtime\Runtime\Runtime;
use Override;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Ulid;

/**
 * The entry point for spawning actors and driving the actor system event loop.
 *
 * An ActorSystem is the top-level container for your actor hierarchy. You
 * typically create one per process, configure it with a Runtime (FiberRuntime,
 * SwooleRuntime, or StepRuntime), and use it to spawn the root actors of your
 * supervision tree. Call run() to block until the system is shut down.
 *
 * Example:
 * ```php
 * $system = ActorSystem::create('my-app', new FiberRuntime());
 * $ref    = $system->spawn(Props::fromBehavior($greeter), 'greeter');
 * $ref->tell(new Greet('world'));
 * $system->run();
 * ```
 *
 * @see Props for actor spawn configuration
 * @see Runtime for runtime backend selection
 * @see Behavior for actor behavior definitions
 *
 * @psalm-api
 */
final class ActorSystem
{
    /** @var array<string, ActorRef<object>> */
    private array $children;

    /** @var array<string, ActorCell<object>> */
    private array $cells;

    private bool $stopping = false;

    private int $anonymousCounter = 0;

    private readonly ActorPath $userGuardianPath;

    /**
     * @param array<string, ActorRef<object>> $initialChildren
     * @param array<string, ActorCell<object>> $initialCells
     */
    private function __construct(
        private readonly string $systemName,
        private readonly Runtime $runtime,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly DeadLetterRef $deadLetters,
        private readonly Ulid $writerId,
        array $initialChildren,
        array $initialCells,
    ) {
        $this->children = $initialChildren;
        $this->cells = $initialCells;
        $this->userGuardianPath = ActorPath::fromString('/user');
    }

    /**
     * Create a new ActorSystem with the given name and runtime.
     *
     * The system name is used as a namespace prefix in actor paths (e.g. /user/greeter).
     * Optional dependencies default to a wall-clock, a NullLogger, and a no-op dispatcher.
     *
     * @param string $name A short identifier for this system; appears in actor paths and logs.
     * @param Runtime $runtime The concurrency backend (FiberRuntime, SwooleRuntime, StepRuntime).
     * @param ClockInterface|null $clock PSR-20 clock; defaults to wall-clock DateTimeImmutable.
     * @param LoggerInterface|null $logger PSR-3 logger; defaults to NullLogger.
     * @param EventDispatcherInterface|null $eventDispatcher PSR-14 dispatcher; defaults to no-op.
     */
    public static function create(
        string $name,
        Runtime $runtime,
        ?ClockInterface $clock = null,
        ?LoggerInterface $logger = null,
        ?EventDispatcherInterface $eventDispatcher = null,
    ): self {
        $resolvedClock = $clock ?? new class implements ClockInterface {
            #[Override]
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable();
            }
        };

        return new self(
            $name,
            $runtime,
            $resolvedClock,
            $logger ?? new NullLogger(),
            $eventDispatcher ?? new NullDispatcher(),
            new DeadLetterRef(),
            self::generateUlid(),
            [],
            [],
        );
    }

    /**
     * Spawn a named actor under the /user guardian and return its reference.
     *
     * The actor is started immediately; its PreStart signal fires before this
     * method returns. If an actor with the same name already exists and is still
     * alive, ActorNameExistsException is thrown. A previously stopped actor with
     * the same name is silently replaced.
     *
     * @template T of object
     * @param Props<T> $props Spawn configuration (behavior, mailbox, supervision).
     * @param string $name Unique name within the /user guardian; used in the actor path.
     * @return ActorRef<T>
     * @throws ActorInitializationException if the behavior's setup phase throws.
     * @throws ActorNameExistsException if a live actor with this name already exists.
     */
    public function spawn(Props $props, string $name): ActorRef
    {
        if (isset($this->children[$name])) {
            if ($this->children[$name]->isAlive()) {
                throw new ActorNameExistsException($this->userGuardianPath, $name);
            }

            unset($this->children[$name], $this->cells[$name]);
        }

        [$ref, $cell] = $this->createActorCell($props, $name);
        $this->children[$name] = $ref;
        $this->cells[$name] = $cell;

        return $ref;
    }

    /**
     * Spawn an anonymous actor with an auto-generated name under the /user guardian.
     *
     * The generated name has the form auto-N where N is a monotonically increasing
     * counter. Use this when the actor's logical identity does not matter (e.g. a
     * fire-and-forget worker). Prefer spawn() when you need to look up the actor
     * later via a stable name.
     *
     * @template T of object
     * @param Props<T> $props Spawn configuration (behavior, mailbox, supervision).
     * @return ActorRef<T>
     * @throws ActorInitializationException if the behavior's setup phase throws.
     */
    public function spawnAnonymous(Props $props): ActorRef
    {
        $name = 'auto-' . $this->anonymousCounter++;
        [$ref, $cell] = $this->createActorCell($props, $name);
        $this->children[$name] = $ref;
        $this->cells[$name] = $cell;

        return $ref;
    }

    /**
     * Gracefully stop an actor by sending it a PoisonPill.
     *
     * The actor processes all messages already in its mailbox before honouring
     * the PoisonPill, then delivers PostStop and stops its children. This method
     * returns immediately; the stop happens asynchronously.
     *
     * @param ActorRef<object> $ref The actor to stop.
     */
    public function stop(ActorRef $ref): void
    {
        $ref->tell(new PoisonPill());
    }

    /**
     * Returns the shared dead-letter reference.
     */
    public function deadLetters(): DeadLetterRef
    {
        return $this->deadLetters;
    }

    /**
     * Returns the system name.
     */
    public function name(): string
    {
        return $this->systemName;
    }

    /**
     * Returns the unique writer ULID for this actor system instance.
     *
     * Used by the persistence layer to identify which system instance
     * wrote a given event or snapshot (single-writer principle).
     */
    public function writerId(): Ulid
    {
        return $this->writerId;
    }

    /**
     * Returns the configured runtime.
     */
    public function runtime(): Runtime
    {
        return $this->runtime;
    }

    /**
     * Returns the configured clock.
     */
    public function clock(): ClockInterface
    {
        return $this->clock;
    }

    /**
     * Returns the configured event dispatcher.
     */
    public function eventDispatcher(): EventDispatcherInterface
    {
        return $this->eventDispatcher;
    }

    /**
     * Start the runtime event loop and block until the system shuts down.
     *
     * This is the main blocking call that drives actor message processing.
     * Use scheduleOnce() or a root actor to trigger shutdown() when the
     * application has finished its work.
     */
    public function run(): void
    {
        $this->runtime->run();
    }

    /**
     * Gracefully shut down the system within the given timeout.
     *
     * 1. Marks the system as stopping (idempotent on repeated calls).
     * 2. Sends PoisonPill to all top-level actors so cooperative actors stop cleanly.
     * 3. Yields cooperatively until all children are stopped or the deadline expires.
     * 4. Force-stops any survivors by calling initiateStop() directly, which closes
     *    their mailbox (unblocking dequeueBlocking) and delivers PostStop.
     * 5. Signals the runtime to shut down.
     */
    public function shutdown(Duration $timeout): void
    {
        if ($this->stopping) {
            return;
        }

        $this->stopping = true;

        $deadlineNanos = hrtime(true) + $timeout->toNanos();

        foreach ($this->children as $child) {
            $child->tell(new PoisonPill());
        }

        while (hrtime(true) < $deadlineNanos && $this->hasAliveChildren()) {
            $this->runtime->yield();
        }

        foreach ($this->cells as $cell) {
            if ($cell->isAlive()) {
                $cell->initiateStop();
            }
        }

        $this->runtime->shutdown($timeout);
    }

    /**
     * Whether the system is currently running.
     */
    public function isRunning(): bool
    {
        return $this->runtime->isRunning();
    }

    private function hasAliveChildren(): bool
    {
        foreach ($this->children as $child) {
            if ($child->isAlive()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @template T of object
     * @param Props<T> $props
     * @return array{ActorRef<T>, ActorCell<object>}
     * @throws ActorInitializationException
     * @psalm-suppress MoreSpecificReturnType,LessSpecificReturnStatement
     */
    private function createActorCell(Props $props, string $name): array
    {
        $childPath = $this->userGuardianPath->child($name);
        /** @var Mailbox<Envelope> $childMailbox */
        $childMailbox = $this->runtime->createMailbox($props->mailbox);

        $typedSupervision = $props->supervision ?? SupervisionStrategy::oneForOne();

        $childCell = new ActorCell(
            $props->behavior,
            $childPath,
            $childMailbox,
            $this->runtime,
            null,
            $typedSupervision,
            $this->clock,
            $this->logger,
            $this->deadLetters,
        );
        $childCell->start();

        $this->spawnMessageLoop($childCell, $childMailbox);

        /** @var ActorCell<object> $childCell */
        return [$childCell->self(), $childCell];
    }

    /**
     * Spawn a fiber that dequeues messages from the mailbox and processes them.
     *
     * @param ActorCell<object> $cell
     * @param Mailbox<Envelope> $mailbox
     */
    private function spawnMessageLoop(ActorCell $cell, Mailbox $mailbox): void
    {
        $this->runtime->spawn(static function () use ($cell, $mailbox): void {
            while ($cell->isAlive()) {
                try {
                    $envelope = $mailbox->dequeueBlocking(Duration::seconds(1));
                    $cell->processMessage($envelope);
                } catch (MailboxClosedException) {
                    break;
                } catch (MailboxTimeoutException) {
                    // No message arrived within the timeout window; re-check isAlive() and retry.
                }
            }
        });
    }

    /**
     * Generate a ULID (Universally Unique Lexicographically Sortable Identifier).
     */
    private static function generateUlid(): Ulid
    {
        return new Ulid();
    }
}
