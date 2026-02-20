<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\Tests\Unit\EventSourced;

use Fp\Functional\Option\Option;
use Monadial\Nexus\Core\Actor\ActorCell;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\DeadLetterRef;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Mailbox\Envelope;
use Monadial\Nexus\Core\Supervision\SupervisionStrategy;
use Monadial\Nexus\Core\Tests\Support\TestMailbox;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Monadial\Nexus\Persistence\Event\InMemoryEventStore;
use Monadial\Nexus\Persistence\EventSourced\AbstractEventSourcedActor;
use Monadial\Nexus\Persistence\EventSourced\Effect;
use Monadial\Nexus\Persistence\EventSourced\RetentionPolicy;
use Monadial\Nexus\Persistence\EventSourced\SnapshotStrategy;
use Monadial\Nexus\Persistence\PersistenceId;
use Monadial\Nexus\Persistence\Snapshot\InMemorySnapshotStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

// --- Test message and state classes ---

final readonly class CounterState
{
    public function __construct(public int $count)
    {
    }
}

final readonly class Increment
{
}

final readonly class Incremented
{
}

final readonly class InspectState
{
}

// --- Concrete test actor ---

final class TestCounterActor extends AbstractEventSourcedActor
{
    public function persistenceId(): PersistenceId
    {
        return PersistenceId::of('counter', 'test-1');
    }

    public function emptyState(): object
    {
        return new CounterState(0);
    }

    public function handleCommand(object $state, ActorContext $ctx, object $command): Effect
    {
        if ($command instanceof Increment) {
            return Effect::persist(new Incremented());
        }

        return Effect::none();
    }

    public function applyEvent(object $state, object $event): object
    {
        if ($event instanceof Incremented) {
            return new CounterState($state->count + 1);
        }

        return $state;
    }
}

#[CoversClass(AbstractEventSourcedActor::class)]
final class AbstractEventSourcedActorTest extends TestCase
{
    private InMemoryEventStore $eventStore;
    private TestCounterActor $actor;

    protected function setUp(): void
    {
        $this->eventStore = new InMemoryEventStore();
        $this->actor = new TestCounterActor($this->eventStore);
    }

    // ========================================================================
    // Test 1: toBehavior() returns a Behavior instance
    // ========================================================================

    #[Test]
    public function toBehaviorReturnsBehaviorInstance(): void
    {
        $behavior = $this->actor->toBehavior();

        self::assertInstanceOf(Behavior::class, $behavior);
    }

    // ========================================================================
    // Test 2: toProps() returns a Props instance
    // ========================================================================

    #[Test]
    public function toPropsReturnsPropsInstance(): void
    {
        $props = $this->actor->toProps();

        self::assertInstanceOf(Props::class, $props);
    }

    // ========================================================================
    // Test 3: withSnapshotStore() returns new instance (immutability via clone)
    // ========================================================================

    #[Test]
    public function withSnapshotStoreReturnsNewImmutableInstance(): void
    {
        $original = $this->actor;
        $withStore = $original->withSnapshotStore(new InMemorySnapshotStore());

        self::assertInstanceOf(TestCounterActor::class, $withStore);
        self::assertNotSame($original, $withStore);
    }

    // ========================================================================
    // Test 4: withSnapshotStrategy() returns new instance
    // ========================================================================

    #[Test]
    public function withSnapshotStrategyReturnsNewImmutableInstance(): void
    {
        $original = $this->actor;
        $withStrategy = $original->withSnapshotStrategy(SnapshotStrategy::everyN(100));

        self::assertInstanceOf(TestCounterActor::class, $withStrategy);
        self::assertNotSame($original, $withStrategy);
    }

    // ========================================================================
    // Test 5: withRetention() returns new instance
    // ========================================================================

    #[Test]
    public function withRetentionReturnsNewImmutableInstance(): void
    {
        $original = $this->actor;
        $withRetention = $original->withRetention(RetentionPolicy::snapshotAndEvents(3, deleteEventsTo: true));

        self::assertInstanceOf(TestCounterActor::class, $withRetention);
        self::assertNotSame($original, $withRetention);
    }

    // ========================================================================
    // Test 6: Full lifecycle — create, get behavior, spawn, send commands,
    //         verify events persisted
    // ========================================================================

    #[Test]
    public function fullLifecycleSendCommandsAndVerifyEventsPersisted(): void
    {
        $eventStore = new InMemoryEventStore();
        $actor = new TestCounterActor($eventStore);

        $behavior = $actor->toBehavior();

        $runtime = new TestRuntime();
        $deadLetters = new DeadLetterRef();
        $logger = new NullLogger();

        $cell = $this->createCell($behavior, $runtime, $deadLetters, $logger);
        $cell->start();

        // Send 3 Increment commands
        $cell->processMessage($this->envelope(new Increment()));
        $cell->processMessage($this->envelope(new Increment()));
        $cell->processMessage($this->envelope(new Increment()));

        // Verify 3 events were persisted
        $persistenceId = PersistenceId::of('counter', 'test-1');
        $events = iterator_to_array($eventStore->load($persistenceId));

        self::assertCount(3, $events);
        self::assertSame(1, $events[0]->sequenceNr);
        self::assertSame(2, $events[1]->sequenceNr);
        self::assertSame(3, $events[2]->sequenceNr);
        self::assertInstanceOf(Incremented::class, $events[0]->event);
        self::assertInstanceOf(Incremented::class, $events[1]->event);
        self::assertInstanceOf(Incremented::class, $events[2]->event);
    }

    // ========================================================================
    // Test 7: Full lifecycle verifies state is correctly updated
    // ========================================================================

    #[Test]
    public function fullLifecycleStateIsUpdatedAfterCommands(): void
    {
        $eventStore = new InMemoryEventStore();
        $stateCapture = null;

        // Create a custom actor that captures state on InspectState command
        $actor = new class ($eventStore, $stateCapture) extends AbstractEventSourcedActor {
            /** @param mixed $stateCapture */
            public function __construct(
                InMemoryEventStore $eventStore,
                private mixed &$stateCapture,
            ) {
                parent::__construct($eventStore);
            }

            public function persistenceId(): PersistenceId
            {
                return PersistenceId::of('counter', 'test-2');
            }

            public function emptyState(): object
            {
                return new CounterState(0);
            }

            public function handleCommand(object $state, ActorContext $ctx, object $command): Effect
            {
                if ($command instanceof Increment) {
                    return Effect::persist(new Incremented());
                }
                if ($command instanceof InspectState) {
                    $this->stateCapture = $state;

                    return Effect::none();
                }

                return Effect::none();
            }

            public function applyEvent(object $state, object $event): object
            {
                if ($event instanceof Incremented) {
                    return new CounterState($state->count + 1);
                }

                return $state;
            }
        };

        $behavior = $actor->toBehavior();

        $runtime = new TestRuntime();
        $deadLetters = new DeadLetterRef();
        $logger = new NullLogger();

        $cell = $this->createCell($behavior, $runtime, $deadLetters, $logger);
        $cell->start();

        $cell->processMessage($this->envelope(new Increment()));
        $cell->processMessage($this->envelope(new Increment()));
        $cell->processMessage($this->envelope(new Increment()));

        // Inspect state to capture it
        $cell->processMessage($this->envelope(new InspectState()));

        self::assertNotNull($stateCapture);
        self::assertInstanceOf(CounterState::class, $stateCapture);
        self::assertSame(3, $stateCapture->count);
    }

    // ========================================================================
    // Test 8: Fluent builder chain works end-to-end
    // ========================================================================

    #[Test]
    public function fullBuilderChainReturnsBehavior(): void
    {
        $behavior = $this->actor
            ->withSnapshotStore(new InMemorySnapshotStore())
            ->withSnapshotStrategy(SnapshotStrategy::everyN(100))
            ->withRetention(RetentionPolicy::snapshotAndEvents(3, deleteEventsTo: true))
            ->toBehavior();

        self::assertInstanceOf(Behavior::class, $behavior);
    }

    // ========================================================================
    // Test 9: toProps() produces Props that contain the behavior
    // ========================================================================

    #[Test]
    public function toPropsContainsBehavior(): void
    {
        $props = $this->actor->toProps();

        self::assertInstanceOf(Props::class, $props);
        self::assertInstanceOf(Behavior::class, $props->behavior);
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    private function createCell(
        Behavior $behavior,
        TestRuntime $runtime,
        DeadLetterRef $deadLetters,
        NullLogger $logger,
        string $path = '/user/test',
    ): ActorCell {
        $actorPath = ActorPath::fromString($path);
        $mailbox = TestMailbox::unbounded();

        /** @var Option<ActorRef<object>> $noParent */
        $noParent = Option::none();

        return new ActorCell(
            $behavior,
            $actorPath,
            $mailbox,
            $runtime,
            $noParent,
            SupervisionStrategy::oneForOne(),
            $runtime->clock(),
            $logger,
            $deadLetters,
        );
    }

    private function envelope(object $message, string $target = '/user/test'): Envelope
    {
        return Envelope::of(
            $message,
            ActorPath::root(),
            ActorPath::fromString($target),
        );
    }
}
