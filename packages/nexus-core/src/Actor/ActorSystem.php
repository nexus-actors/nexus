<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

use DateTimeImmutable;
use Monadial\Nexus\Core\Exception\ActorInitializationException;
use Monadial\Nexus\Core\Exception\ActorNameExistsException;
use Monadial\Nexus\Core\Mailbox\Envelope;
use Monadial\Nexus\Core\Message\PoisonPill;
use Monadial\Nexus\Core\Supervision\SupervisionStrategy;
use Monadial\Nexus\Observability\NoopObservability;
use Monadial\Nexus\Observability\Observability;
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
 * Top-level container and entry point for spawning actors and driving the event loop.
 *
 * An ActorSystem owns the root of the actor supervision tree (the "/user" guardian),
 * holds the chosen {@see \Monadial\Nexus\Runtime\Runtime\Runtime} implementation, and
 * coordinates startup, message dispatch, and graceful shutdown. Each system is stamped
 * with a unique {@see \Symfony\Component\Uid\Ulid} writer id consumed by the persistence
 * layer to enforce the single-writer principle. Typically one ActorSystem is created
 * per process; multiple may coexist in tests with the deterministic StepRuntime.
 *
 * Invariants:
 *  - Top-level child names are unique among live actors; respawn of a dead name replaces it silently.
 *  - {@see ActorSystem::shutdown()} is idempotent — repeated calls after the first are no-ops.
 *  - {@see ActorSystem::run()} blocks the caller; trigger {@see ActorSystem::shutdown()} from a
 *    scheduled callback or root actor to return control.
 *
 * @example
 * ```php
 * $system = ActorSystem::create('my-app', new FiberRuntime());
 * $ref    = $system->spawn(Props::fromBehavior($greeter), 'greeter');
 * $ref->tell(new Greet('world'));
 * $system->runtime()->scheduleOnce(Duration::seconds(1), fn () => $system->shutdown(Duration::seconds(2)));
 * $system->run();
 * ```
 *
 * @see \Monadial\Nexus\Core\Actor\Props for spawn configuration (behavior, mailbox, supervision)
 * @see \Monadial\Nexus\Runtime\Runtime\Runtime for runtime backend selection (Fiber, Swoole, Step)
 * @see \Monadial\Nexus\Core\Actor\Behavior for actor behavior definitions
 * @see \Monadial\Nexus\Core\Actor\ActorRef for typed actor references
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
     * Construct a fully wired ActorSystem. Prefer {@see ActorSystem::create()}.
     *
     * @param string $systemName Short identifier appearing in actor paths and logs.
     * @param Runtime $runtime Concurrency backend driving message dispatch and scheduling.
     * @param ClockInterface $clock PSR-20 clock used for timers and persistence timestamps.
     * @param LoggerInterface $logger PSR-3 logger handed to every actor via {@see ActorContext::log()}.
     * @param EventDispatcherInterface $eventDispatcher PSR-14 dispatcher for system-level events.
     * @param DeadLetterRef $deadLetters Shared sink for undeliverable / unhandled messages.
     * @param Ulid $writerId Per-instance writer identity used by the persistence layer.
     * @param array<string, ActorRef<object>> $initialChildren Pre-built top-level child refs (usually empty).
     * @param array<string, ActorCell<object>> $initialCells Pre-built top-level cells (usually empty).
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
        private readonly Observability $observability,
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
     * @param LoggerInterface|null $logger PSR-3 logger; defaults to {@see NullLogger}.
     * @param EventDispatcherInterface|null $eventDispatcher PSR-14 dispatcher; defaults to no-op.
     * @return self A fresh system with a generated writer id and empty child registry.
     */
    public static function create(
        string $name,
        Runtime $runtime,
        ?ClockInterface $clock = null,
        ?LoggerInterface $logger = null,
        ?EventDispatcherInterface $eventDispatcher = null,
        ?Observability $observability = null,
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
            $observability ?? new NoopObservability(),
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
     * @return ActorRef<T> Live, started reference whose path is /user/{name}.
     * @throws \Monadial\Nexus\Core\Exception\ActorInitializationException if the behavior's setup phase throws.
     * @throws \Monadial\Nexus\Core\Exception\ActorNameExistsException if a live actor with this name already exists.
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
     * @return ActorRef<T> Live, started reference whose path is /user/auto-N.
     * @throws \Monadial\Nexus\Core\Exception\ActorInitializationException if the behavior's setup phase throws.
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
     * @return DeadLetterRef Shared sink for undeliverable or unhandled messages.
     */
    public function deadLetters(): DeadLetterRef
    {
        return $this->deadLetters;
    }

    /**
     * @return string The system name supplied to {@see ActorSystem::create()}.
     */
    public function name(): string
    {
        return $this->systemName;
    }

    /**
     * Unique writer ULID for this actor system instance.
     *
     * Stamped on every persisted envelope so stores can enforce the single-writer
     * principle and {@see \Monadial\Nexus\Persistence\Replay\ReplayFilter} can detect
     * interleaved writers during event recovery.
     *
     * @return Ulid Stable for the lifetime of this instance; regenerated on each {@see ActorSystem::create()} call.
     */
    public function writerId(): Ulid
    {
        return $this->writerId;
    }

    /**
     * @return Runtime The concurrency backend driving message dispatch and timers.
     */
    public function runtime(): Runtime
    {
        return $this->runtime;
    }

    /**
     * @return ClockInterface PSR-20 clock used for scheduler timestamps and persistence metadata.
     */
    public function clock(): ClockInterface
    {
        return $this->clock;
    }

    /**
     * @return EventDispatcherInterface PSR-14 dispatcher used to broadcast system-level events.
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
     *
     * @param Duration $timeout Wall-clock budget for graceful drain before force-stop runs.
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
     * @return bool True while the underlying runtime's event loop is active.
     */
    public function isRunning(): bool
    {
        return $this->runtime->isRunning();
    }

    /**
     * Number of alive root actors currently registered under the system.
     *
     * @return int Count of root children whose actor is still alive.
     */
    public function liveActorCount(): int
    {
        $count = 0;

        foreach ($this->children as $child) {
            if ($child->isAlive()) {
                ++$count;
            }
        }

        return $count;
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
            $this->observability,
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
