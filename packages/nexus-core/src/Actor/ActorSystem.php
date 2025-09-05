<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

use Fp\Collections\HashMap;
use Fp\Functional\Option\Option;
use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Core\Exception\ActorInitializationException;
use Monadial\Nexus\Core\Exception\ActorNameExistsException;
use Monadial\Nexus\Core\Exception\MailboxClosedException;
use Monadial\Nexus\Core\Mailbox\Mailbox;
use Monadial\Nexus\Core\Message\PoisonPill;
use Monadial\Nexus\Core\Runtime\Runtime;
use Monadial\Nexus\Core\Supervision\SupervisionStrategy;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Entry point for the actor hierarchy.
 *
 * Manages the lifecycle of all actors in the system, provides a dead-letter
 * endpoint, and delegates scheduling/concurrency to the injected Runtime.
 */
final class ActorSystem
{
    /** @var HashMap<string, ActorRef<object>> */
    private HashMap $children;

    private int $anonymousCounter = 0;

    private readonly ActorPath $userGuardianPath;

    /**
     * @param HashMap<string, ActorRef<object>> $initialChildren
     */
    private function __construct(
        private readonly string $systemName,
        private readonly Runtime $runtime,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly DeadLetterRef $deadLetters,
        HashMap $initialChildren,
    ) {
        $this->children = $initialChildren;
        $this->userGuardianPath = ActorPath::fromString('/user');
    }

    /**
     * Factory method to create a new ActorSystem.
     */
    public static function create(
        string $name,
        Runtime $runtime,
        ?ClockInterface $clock = null,
        ?LoggerInterface $logger = null,
        ?EventDispatcherInterface $eventDispatcher = null,
    ): self {
        $resolvedClock = $clock ?? new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable();
            }
        };

        /** @var HashMap<string, ActorRef<object>> $emptyChildren */
        $emptyChildren = HashMap::collect([]); // @phpstan-ignore varTag.type

        return new self(
            $name,
            $runtime,
            $resolvedClock,
            $logger ?? new NullLogger(),
            $eventDispatcher ?? new NullDispatcher(),
            new DeadLetterRef(),
            $emptyChildren,
        );
    }

    /**
     * Spawn a named actor under the /user guardian.
     *
     * @template T of object
     * @param Props<T> $props
     * @return ActorRef<T>
     * @throws ActorInitializationException
     * @throws ActorNameExistsException
     */
    public function spawn(Props $props, string $name): ActorRef
    {
        if ($this->children->get($name)->isSome()) { // @phpstan-ignore method.impossibleType
            throw new ActorNameExistsException($this->userGuardianPath, $name);
        }

        $ref = $this->createActorCell($props, $name);
        $this->children = $this->children->appended($name, $ref);

        return $ref;
    }

    /**
     * Spawn an anonymous actor under the /user guardian with an auto-generated name.
     *
     * @template T of object
     * @param Props<T> $props
     * @return ActorRef<T>
     * @throws ActorInitializationException
     */
    public function spawnAnonymous(Props $props): ActorRef
    {
        $name = 'auto-' . $this->anonymousCounter++;
        $ref = $this->createActorCell($props, $name);
        $this->children = $this->children->appended($name, $ref);

        return $ref;
    }

    /**
     * Stop an actor by sending it a PoisonPill.
     *
     * @param ActorRef<object> $ref
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
     * Start the runtime event loop.
     */
    public function run(): void
    {
        $this->runtime->run();
    }

    /**
     * Gracefully shut down the system within the given timeout.
     *
     * Stops all top-level actors (which closes their mailboxes, causing
     * message-processing fibers to terminate), then signals the runtime
     * to shut down.
     */
    public function shutdown(Duration $timeout): void
    {
        // Stop all top-level actors — this closes mailboxes so message loops exit
        foreach ($this->children->values()->toList() as $child) {
            $this->stop($child);
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

    /**
     * @template T of object
     * @param Props<T> $props
     * @return ActorRef<T>
     * @throws ActorInitializationException
     */
    private function createActorCell(Props $props, string $name): ActorRef
    {
        $childPath = $this->userGuardianPath->child($name);
        $childMailbox = $this->runtime->createMailbox($props->mailbox);

        $childSupervision = $props->supervision->isSome() // @phpstan-ignore method.impossibleType
            ? $props->supervision->get()
            : SupervisionStrategy::oneForOne();

        /** @var SupervisionStrategy $typedSupervision */
        $typedSupervision = $childSupervision;

        /** @var Option<ActorRef<object>> $parentOpt */
        $parentOpt = Option::none(); // @phpstan-ignore varTag.type

        /** @var ActorCell<T> $childCell */
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

        $this->spawnMessageLoop($childCell, $childMailbox);

        return $childCell->self();
    }

    /**
     * Spawn a fiber that dequeues messages from the mailbox and processes them.
     *
     * @param ActorCell<object> $cell
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
                }
            }
        });
    }
}
