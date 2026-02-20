<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\Tests\Unit\EventSourced;

use Closure;
use DateTimeImmutable;
use Fp\Functional\Option\Option;
use Monadial\Nexus\Core\Actor\ActorCell;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\DeadLetterRef;
use Monadial\Nexus\Core\Mailbox\Envelope;
use Monadial\Nexus\Core\Supervision\SupervisionStrategy;
use Monadial\Nexus\Core\Tests\Support\TestMailbox;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Monadial\Nexus\Persistence\Event\EventEnvelope;
use Monadial\Nexus\Persistence\Event\InMemoryEventStore;
use Monadial\Nexus\Persistence\EventSourced\Effect;
use Monadial\Nexus\Persistence\EventSourced\PersistenceEngine;
use Monadial\Nexus\Persistence\EventSourced\RetentionPolicy;
use Monadial\Nexus\Persistence\EventSourced\SnapshotStrategy;
use Monadial\Nexus\Persistence\Locking\LockingStrategy;
use Monadial\Nexus\Persistence\Locking\PessimisticLockProvider;
use Monadial\Nexus\Persistence\PersistenceId;
use Monadial\Nexus\Persistence\Snapshot\InMemorySnapshotStore;
use Monadial\Nexus\Persistence\Snapshot\SnapshotEnvelope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

// --- Test message and state classes ---

final readonly class AddItem
{
    public function __construct(public string $item) {}
}

final readonly class RemoveItem
{
    public function __construct(public string $item) {}
}

final readonly class ItemAdded
{
    public function __construct(public string $item) {}
}

final readonly class ItemRemoved
{
    public function __construct(public string $item) {}
}

final readonly class GetItems
{
    public function __construct(public ActorRef $replyTo) {}
}

final readonly class ItemsReply
{
    public function __construct(public array $items) {}
}

final readonly class DoNothing {}

final readonly class StopCommand {}

final readonly class ShoppingCart
{
    /** @param list<string> $items */
    public function __construct(public array $items = []) {}
}

#[CoversClass(PersistenceEngine::class)]
final class PersistenceEngineTest extends TestCase
{
    private TestRuntime $runtime;
    private DeadLetterRef $deadLetters;
    private NullLogger $logger;
    private PersistenceId $persistenceId;

    protected function setUp(): void
    {
        $this->runtime = new TestRuntime();
        $this->deadLetters = new DeadLetterRef();
        $this->logger = new NullLogger();
        $this->persistenceId = PersistenceId::of('ShoppingCart', 'cart-1');
    }

    // ========================================================================
    // Test 1: Recovery from empty state
    // ========================================================================

    #[Test]
    public function recovery_from_empty_state_uses_empty_state(): void
    {
        $eventStore = new InMemoryEventStore();
        $recoveredState = null;

        $behavior = PersistenceEngine::create(
            $this->persistenceId,
            new ShoppingCart(),
            static function (object $state, ActorContext $ctx, object $msg) use (&$recoveredState): Effect {
                $recoveredState = $state;

                return Effect::none();
            },
            static fn(object $state, object $event): object => $state,
            $eventStore,
        );

        $cell = $this->createCell($behavior);
        $cell->start();

        // Send a message to trigger the command handler
        $cell->processMessage($this->envelope(new DoNothing()));

        self::assertNotNull($recoveredState);
        self::assertInstanceOf(ShoppingCart::class, $recoveredState);
        self::assertSame([], $recoveredState->items);
    }

    // ========================================================================
    // Test 2: Command processing with persist
    // ========================================================================

    #[Test]
    public function command_processing_persists_events_and_updates_state(): void
    {
        $eventStore = new InMemoryEventStore();
        $stateAfterPersist = null;

        $behavior = PersistenceEngine::create(
            $this->persistenceId,
            new ShoppingCart(),
            static function (object $state, ActorContext $ctx, object $msg) use (&$stateAfterPersist): Effect {
                if ($msg instanceof AddItem) {
                    return Effect::persist(new ItemAdded($msg->item));
                }
                if ($msg instanceof DoNothing) {
                    $stateAfterPersist = $state;

                    return Effect::none();
                }

                return Effect::none();
            },
            static function (object $state, object $event): object {
                if ($event instanceof ItemAdded) {
                    return new ShoppingCart([...$state->items, $event->item]);
                }

                return $state;
            },
            $eventStore,
        );

        $cell = $this->createCell($behavior);
        $cell->start();

        // Add an item
        $cell->processMessage($this->envelope(new AddItem('apple')));

        // Verify events were persisted in the EventStore
        $events = iterator_to_array($eventStore->load($this->persistenceId));
        self::assertCount(1, $events);
        self::assertSame(1, $events[0]->sequenceNr);
        self::assertInstanceOf(ItemAdded::class, $events[0]->event);
        self::assertSame('apple', $events[0]->event->item);
        self::assertSame(ItemAdded::class, $events[0]->eventType);

        // Verify state was updated (send another message to inspect state)
        $cell->processMessage($this->envelope(new DoNothing()));
        self::assertNotNull($stateAfterPersist);
        self::assertInstanceOf(ShoppingCart::class, $stateAfterPersist);
        self::assertSame(['apple'], $stateAfterPersist->items);
    }

    #[Test]
    public function command_processing_persists_multiple_events(): void
    {
        $eventStore = new InMemoryEventStore();

        $behavior = PersistenceEngine::create(
            $this->persistenceId,
            new ShoppingCart(),
            static function (object $state, ActorContext $ctx, object $msg): Effect {
                if ($msg instanceof AddItem) {
                    return Effect::persist(
                        new ItemAdded($msg->item),
                        new ItemAdded($msg->item . '-bonus'),
                    );
                }

                return Effect::none();
            },
            static function (object $state, object $event): object {
                if ($event instanceof ItemAdded) {
                    return new ShoppingCart([...$state->items, $event->item]);
                }

                return $state;
            },
            $eventStore,
        );

        $cell = $this->createCell($behavior);
        $cell->start();

        $cell->processMessage($this->envelope(new AddItem('apple')));

        $events = iterator_to_array($eventStore->load($this->persistenceId));
        self::assertCount(2, $events);
        self::assertSame(1, $events[0]->sequenceNr);
        self::assertSame(2, $events[1]->sequenceNr);
        self::assertSame('apple', $events[0]->event->item);
        self::assertSame('apple-bonus', $events[1]->event->item);
    }

    #[Test]
    public function multiple_commands_increment_sequence_numbers(): void
    {
        $eventStore = new InMemoryEventStore();

        $behavior = PersistenceEngine::create(
            $this->persistenceId,
            new ShoppingCart(),
            static function (object $state, ActorContext $ctx, object $msg): Effect {
                if ($msg instanceof AddItem) {
                    return Effect::persist(new ItemAdded($msg->item));
                }

                return Effect::none();
            },
            static function (object $state, object $event): object {
                if ($event instanceof ItemAdded) {
                    return new ShoppingCart([...$state->items, $event->item]);
                }

                return $state;
            },
            $eventStore,
        );

        $cell = $this->createCell($behavior);
        $cell->start();

        $cell->processMessage($this->envelope(new AddItem('apple')));
        $cell->processMessage($this->envelope(new AddItem('banana')));
        $cell->processMessage($this->envelope(new AddItem('cherry')));

        $events = iterator_to_array($eventStore->load($this->persistenceId));
        self::assertCount(3, $events);
        self::assertSame(1, $events[0]->sequenceNr);
        self::assertSame(2, $events[1]->sequenceNr);
        self::assertSame(3, $events[2]->sequenceNr);
    }

    // ========================================================================
    // Test 3: Recovery from events
    // ========================================================================

    #[Test]
    public function recovery_from_events_replays_state(): void
    {
        $eventStore = new InMemoryEventStore();

        // Pre-populate the event store
        $eventStore->persist(
            $this->persistenceId,
            new EventEnvelope($this->persistenceId, 1, new ItemAdded('apple'), ItemAdded::class, new DateTimeImmutable()),
            new EventEnvelope($this->persistenceId, 2, new ItemAdded('banana'), ItemAdded::class, new DateTimeImmutable()),
        );

        $recoveredState = null;

        $behavior = PersistenceEngine::create(
            $this->persistenceId,
            new ShoppingCart(),
            static function (object $state, ActorContext $ctx, object $msg) use (&$recoveredState): Effect {
                $recoveredState = $state;

                return Effect::none();
            },
            static function (object $state, object $event): object {
                if ($event instanceof ItemAdded) {
                    return new ShoppingCart([...$state->items, $event->item]);
                }

                return $state;
            },
            $eventStore,
        );

        $cell = $this->createCell($behavior);
        $cell->start();

        // Send a message to get the recovered state
        $cell->processMessage($this->envelope(new DoNothing()));

        self::assertNotNull($recoveredState);
        self::assertInstanceOf(ShoppingCart::class, $recoveredState);
        self::assertSame(['apple', 'banana'], $recoveredState->items);
    }

    #[Test]
    public function recovery_continues_sequence_numbers_after_replay(): void
    {
        $eventStore = new InMemoryEventStore();

        // Pre-populate with 2 events
        $eventStore->persist(
            $this->persistenceId,
            new EventEnvelope($this->persistenceId, 1, new ItemAdded('apple'), ItemAdded::class, new DateTimeImmutable()),
            new EventEnvelope($this->persistenceId, 2, new ItemAdded('banana'), ItemAdded::class, new DateTimeImmutable()),
        );

        $behavior = PersistenceEngine::create(
            $this->persistenceId,
            new ShoppingCart(),
            static function (object $state, ActorContext $ctx, object $msg): Effect {
                if ($msg instanceof AddItem) {
                    return Effect::persist(new ItemAdded($msg->item));
                }

                return Effect::none();
            },
            static function (object $state, object $event): object {
                if ($event instanceof ItemAdded) {
                    return new ShoppingCart([...$state->items, $event->item]);
                }

                return $state;
            },
            $eventStore,
        );

        $cell = $this->createCell($behavior);
        $cell->start();

        // New command should produce sequenceNr=3
        $cell->processMessage($this->envelope(new AddItem('cherry')));

        $events = iterator_to_array($eventStore->load($this->persistenceId));
        self::assertCount(3, $events);
        self::assertSame(3, $events[2]->sequenceNr);
        self::assertSame('cherry', $events[2]->event->item);
    }

    // ========================================================================
    // Test 4: Recovery from snapshot + events
    // ========================================================================

    #[Test]
    public function recovery_from_snapshot_plus_events(): void
    {
        $eventStore = new InMemoryEventStore();
        $snapshotStore = new InMemorySnapshotStore();

        // Pre-populate snapshot at sequenceNr=2 with ['apple', 'banana']
        $snapshotStore->save($this->persistenceId, new SnapshotEnvelope(
            $this->persistenceId,
            2,
            new ShoppingCart(['apple', 'banana']),
            ShoppingCart::class,
            new DateTimeImmutable(),
        ));

        // Pre-populate events: 1, 2 (before snapshot), 3 (after snapshot)
        $eventStore->persist(
            $this->persistenceId,
            new EventEnvelope($this->persistenceId, 1, new ItemAdded('apple'), ItemAdded::class, new DateTimeImmutable()),
            new EventEnvelope($this->persistenceId, 2, new ItemAdded('banana'), ItemAdded::class, new DateTimeImmutable()),
            new EventEnvelope($this->persistenceId, 3, new ItemAdded('cherry'), ItemAdded::class, new DateTimeImmutable()),
        );

        $recoveredState = null;

        $behavior = PersistenceEngine::create(
            $this->persistenceId,
            new ShoppingCart(),
            static function (object $state, ActorContext $ctx, object $msg) use (&$recoveredState): Effect {
                $recoveredState = $state;

                return Effect::none();
            },
            static function (object $state, object $event): object {
                if ($event instanceof ItemAdded) {
                    return new ShoppingCart([...$state->items, $event->item]);
                }

                return $state;
            },
            $eventStore,
            $snapshotStore,
        );

        $cell = $this->createCell($behavior);
        $cell->start();

        $cell->processMessage($this->envelope(new DoNothing()));

        // State should be from snapshot (['apple', 'banana']) + event 3 ('cherry')
        self::assertNotNull($recoveredState);
        self::assertInstanceOf(ShoppingCart::class, $recoveredState);
        self::assertSame(['apple', 'banana', 'cherry'], $recoveredState->items);
    }

    #[Test]
    public function recovery_from_snapshot_without_subsequent_events(): void
    {
        $eventStore = new InMemoryEventStore();
        $snapshotStore = new InMemorySnapshotStore();

        // Snapshot at seqNr=2, no events after that
        $snapshotStore->save($this->persistenceId, new SnapshotEnvelope(
            $this->persistenceId,
            2,
            new ShoppingCart(['apple', 'banana']),
            ShoppingCart::class,
            new DateTimeImmutable(),
        ));

        $recoveredState = null;

        $behavior = PersistenceEngine::create(
            $this->persistenceId,
            new ShoppingCart(),
            static function (object $state, ActorContext $ctx, object $msg) use (&$recoveredState): Effect {
                $recoveredState = $state;

                return Effect::none();
            },
            static function (object $state, object $event): object {
                if ($event instanceof ItemAdded) {
                    return new ShoppingCart([...$state->items, $event->item]);
                }

                return $state;
            },
            $eventStore,
            $snapshotStore,
        );

        $cell = $this->createCell($behavior);
        $cell->start();

        $cell->processMessage($this->envelope(new DoNothing()));

        self::assertNotNull($recoveredState);
        self::assertSame(['apple', 'banana'], $recoveredState->items);
    }

    // ========================================================================
    // Test 5: Snapshot triggered by strategy
    // ========================================================================

    #[Test]
    public function snapshot_triggered_by_every_n_strategy(): void
    {
        $eventStore = new InMemoryEventStore();
        $snapshotStore = new InMemorySnapshotStore();

        $behavior = PersistenceEngine::create(
            $this->persistenceId,
            new ShoppingCart(),
            static function (object $state, ActorContext $ctx, object $msg): Effect {
                if ($msg instanceof AddItem) {
                    return Effect::persist(new ItemAdded($msg->item));
                }

                return Effect::none();
            },
            static function (object $state, object $event): object {
                if ($event instanceof ItemAdded) {
                    return new ShoppingCart([...$state->items, $event->item]);
                }

                return $state;
            },
            $eventStore,
            $snapshotStore,
            SnapshotStrategy::everyN(2),
        );

        $cell = $this->createCell($behavior);
        $cell->start();

        // Event 1 - no snapshot (1 % 2 != 0)
        $cell->processMessage($this->envelope(new AddItem('apple')));
        self::assertNull($snapshotStore->load($this->persistenceId));

        // Event 2 - snapshot should be taken (2 % 2 == 0)
        $cell->processMessage($this->envelope(new AddItem('banana')));
        $snapshot = $snapshotStore->load($this->persistenceId);
        self::assertNotNull($snapshot);
        self::assertSame(2, $snapshot->sequenceNr);
        self::assertInstanceOf(ShoppingCart::class, $snapshot->state);
        self::assertSame(['apple', 'banana'], $snapshot->state->items);
        self::assertSame(ShoppingCart::class, $snapshot->stateType);

        // Event 3 - no new snapshot (3 % 2 != 0)
        $cell->processMessage($this->envelope(new AddItem('cherry')));
        $snapshot = $snapshotStore->load($this->persistenceId);
        self::assertSame(2, $snapshot->sequenceNr); // still the old snapshot

        // Event 4 - another snapshot (4 % 2 == 0)
        $cell->processMessage($this->envelope(new AddItem('date')));
        $snapshot = $snapshotStore->load($this->persistenceId);
        self::assertSame(4, $snapshot->sequenceNr);
        self::assertSame(['apple', 'banana', 'cherry', 'date'], $snapshot->state->items);
    }

    // ========================================================================
    // Test 6: Effect::none() leaves state unchanged
    // ========================================================================

    #[Test]
    public function effect_none_leaves_state_unchanged(): void
    {
        $eventStore = new InMemoryEventStore();
        $states = [];

        $behavior = PersistenceEngine::create(
            $this->persistenceId,
            new ShoppingCart(),
            static function (object $state, ActorContext $ctx, object $msg) use (&$states): Effect {
                $states[] = $state;
                if ($msg instanceof AddItem) {
                    return Effect::persist(new ItemAdded($msg->item));
                }

                return Effect::none();
            },
            static function (object $state, object $event): object {
                if ($event instanceof ItemAdded) {
                    return new ShoppingCart([...$state->items, $event->item]);
                }

                return $state;
            },
            $eventStore,
        );

        $cell = $this->createCell($behavior);
        $cell->start();

        // Add an item first
        $cell->processMessage($this->envelope(new AddItem('apple')));

        // Send DoNothing (effect::none) -- should not change state
        $cell->processMessage($this->envelope(new DoNothing()));
        $cell->processMessage($this->envelope(new DoNothing()));

        // No new events should be persisted after the first one
        $events = iterator_to_array($eventStore->load($this->persistenceId));
        self::assertCount(1, $events);

        // All three state observations: first sees empty, second sees [apple], third sees [apple]
        self::assertCount(3, $states);
        self::assertSame([], $states[0]->items);
        self::assertSame(['apple'], $states[1]->items);
        self::assertSame(['apple'], $states[2]->items);
    }

    // ========================================================================
    // Test 7: Effect::reply() sends reply
    // ========================================================================

    #[Test]
    public function effect_reply_sends_reply_message(): void
    {
        $eventStore = new InMemoryEventStore();
        $replyCapture = new DeadLetterRef();

        $behavior = PersistenceEngine::create(
            $this->persistenceId,
            new ShoppingCart(),
            static function (object $state, ActorContext $ctx, object $msg) use ($replyCapture): Effect {
                if ($msg instanceof AddItem) {
                    return Effect::persist(new ItemAdded($msg->item));
                }
                if ($msg instanceof GetItems) {
                    return Effect::reply($msg->replyTo, new ItemsReply($state->items));
                }

                return Effect::none();
            },
            static function (object $state, object $event): object {
                if ($event instanceof ItemAdded) {
                    return new ShoppingCart([...$state->items, $event->item]);
                }

                return $state;
            },
            $eventStore,
        );

        $cell = $this->createCell($behavior);
        $cell->start();

        $cell->processMessage($this->envelope(new AddItem('apple')));
        $cell->processMessage($this->envelope(new AddItem('banana')));

        // Ask for items using DeadLetterRef as reply target (it captures messages)
        $cell->processMessage($this->envelope(new GetItems($replyCapture)));

        $captured = $replyCapture->captured();
        self::assertCount(1, $captured);
        self::assertInstanceOf(ItemsReply::class, $captured[0]);
        self::assertSame(['apple', 'banana'], $captured[0]->items);

        // No events should be persisted for the reply
        $events = iterator_to_array($eventStore->load($this->persistenceId));
        self::assertCount(2, $events); // only the two AddItem events
    }

    // ========================================================================
    // Test 8: Effect::stop() stops the actor
    // ========================================================================

    #[Test]
    public function effect_stop_stops_the_actor(): void
    {
        $eventStore = new InMemoryEventStore();

        $behavior = PersistenceEngine::create(
            $this->persistenceId,
            new ShoppingCart(),
            static function (object $state, ActorContext $ctx, object $msg): Effect {
                if ($msg instanceof StopCommand) {
                    return Effect::stop();
                }

                return Effect::none();
            },
            static fn(object $state, object $event): object => $state,
            $eventStore,
        );

        $cell = $this->createCell($behavior);
        $cell->start();

        self::assertTrue($cell->isAlive());

        $cell->processMessage($this->envelope(new StopCommand()));

        self::assertFalse($cell->isAlive());
    }

    // ========================================================================
    // Test 9: Side effects executed after persist (thenRun)
    // ========================================================================

    #[Test]
    public function side_effects_executed_after_persist(): void
    {
        $eventStore = new InMemoryEventStore();
        $sideEffectLog = [];

        $behavior = PersistenceEngine::create(
            $this->persistenceId,
            new ShoppingCart(),
            static function (object $state, ActorContext $ctx, object $msg) use (&$sideEffectLog): Effect {
                if ($msg instanceof AddItem) {
                    return Effect::persist(new ItemAdded($msg->item))
                        ->thenRun(static function (object $newState) use (&$sideEffectLog): void {
                            $sideEffectLog[] = 'after-persist: ' . implode(',', $newState->items);
                        });
                }

                return Effect::none();
            },
            static function (object $state, object $event): object {
                if ($event instanceof ItemAdded) {
                    return new ShoppingCart([...$state->items, $event->item]);
                }

                return $state;
            },
            $eventStore,
        );

        $cell = $this->createCell($behavior);
        $cell->start();

        $cell->processMessage($this->envelope(new AddItem('apple')));

        self::assertCount(1, $sideEffectLog);
        // Side effect should see the NEW state (after events applied)
        self::assertSame('after-persist: apple', $sideEffectLog[0]);

        // Add another item
        $cell->processMessage($this->envelope(new AddItem('banana')));

        self::assertCount(2, $sideEffectLog);
        self::assertSame('after-persist: apple,banana', $sideEffectLog[1]);
    }

    #[Test]
    public function then_reply_side_effect_sends_reply_with_new_state(): void
    {
        $eventStore = new InMemoryEventStore();
        $replyCapture = new DeadLetterRef();

        $behavior = PersistenceEngine::create(
            $this->persistenceId,
            new ShoppingCart(),
            static function (object $state, ActorContext $ctx, object $msg) use ($replyCapture): Effect {
                if ($msg instanceof AddItem) {
                    return Effect::persist(new ItemAdded($msg->item))
                        ->thenReply($replyCapture, static function (object $newState): object {
                            return new ItemsReply($newState->items);
                        });
                }

                return Effect::none();
            },
            static function (object $state, object $event): object {
                if ($event instanceof ItemAdded) {
                    return new ShoppingCart([...$state->items, $event->item]);
                }

                return $state;
            },
            $eventStore,
        );

        $cell = $this->createCell($behavior);
        $cell->start();

        $cell->processMessage($this->envelope(new AddItem('apple')));

        $captured = $replyCapture->captured();
        self::assertCount(1, $captured);
        self::assertInstanceOf(ItemsReply::class, $captured[0]);
        self::assertSame(['apple'], $captured[0]->items);
    }

    // ========================================================================
    // Test 10: Retention policy deletes old events after snapshot
    // ========================================================================

    #[Test]
    public function retention_policy_deletes_events_after_snapshot(): void
    {
        $eventStore = new InMemoryEventStore();
        $snapshotStore = new InMemorySnapshotStore();

        $behavior = PersistenceEngine::create(
            $this->persistenceId,
            new ShoppingCart(),
            static function (object $state, ActorContext $ctx, object $msg): Effect {
                if ($msg instanceof AddItem) {
                    return Effect::persist(new ItemAdded($msg->item));
                }

                return Effect::none();
            },
            static function (object $state, object $event): object {
                if ($event instanceof ItemAdded) {
                    return new ShoppingCart([...$state->items, $event->item]);
                }

                return $state;
            },
            $eventStore,
            $snapshotStore,
            SnapshotStrategy::everyN(2),
            RetentionPolicy::snapshotAndEvents(1, deleteEventsTo: true),
        );

        $cell = $this->createCell($behavior);
        $cell->start();

        // Persist 2 events -- triggers snapshot at seqNr=2, which triggers event deletion
        $cell->processMessage($this->envelope(new AddItem('apple')));
        $cell->processMessage($this->envelope(new AddItem('banana')));

        // Events up to seqNr=2 should be deleted
        $events = iterator_to_array($eventStore->load($this->persistenceId));
        self::assertCount(0, $events);

        // Snapshot should still exist
        $snapshot = $snapshotStore->load($this->persistenceId);
        self::assertNotNull($snapshot);
        self::assertSame(2, $snapshot->sequenceNr);
        self::assertSame(['apple', 'banana'], $snapshot->state->items);
    }

    #[Test]
    public function retention_policy_none_does_not_delete_events(): void
    {
        $eventStore = new InMemoryEventStore();
        $snapshotStore = new InMemorySnapshotStore();

        $behavior = PersistenceEngine::create(
            $this->persistenceId,
            new ShoppingCart(),
            static function (object $state, ActorContext $ctx, object $msg): Effect {
                if ($msg instanceof AddItem) {
                    return Effect::persist(new ItemAdded($msg->item));
                }

                return Effect::none();
            },
            static function (object $state, object $event): object {
                if ($event instanceof ItemAdded) {
                    return new ShoppingCart([...$state->items, $event->item]);
                }

                return $state;
            },
            $eventStore,
            $snapshotStore,
            SnapshotStrategy::everyN(2),
            RetentionPolicy::none(), // default: no deletion
        );

        $cell = $this->createCell($behavior);
        $cell->start();

        $cell->processMessage($this->envelope(new AddItem('apple')));
        $cell->processMessage($this->envelope(new AddItem('banana')));

        // All events should still exist
        $events = iterator_to_array($eventStore->load($this->persistenceId));
        self::assertCount(2, $events);
    }

    // ========================================================================
    // Test: Effect::stash() stashes the message
    // ========================================================================

    #[Test]
    public function effect_stash_stashes_message_in_context(): void
    {
        $eventStore = new InMemoryEventStore();
        $stashCalled = false;

        $behavior = PersistenceEngine::create(
            $this->persistenceId,
            new ShoppingCart(),
            static function (object $state, ActorContext $ctx, object $msg) use (&$stashCalled): Effect {
                if ($msg instanceof DoNothing) {
                    $stashCalled = true;

                    return Effect::stash();
                }

                return Effect::none();
            },
            static fn(object $state, object $event): object => $state,
            $eventStore,
        );

        $cell = $this->createCell($behavior);
        $cell->start();

        $cell->processMessage($this->envelope(new DoNothing()));

        // The stash effect should have triggered the stash
        self::assertTrue($stashCalled);
    }

    // ========================================================================
    // Test: Effect::unhandled() leaves state unchanged
    // ========================================================================

    #[Test]
    public function effect_unhandled_leaves_state_unchanged(): void
    {
        $eventStore = new InMemoryEventStore();
        $states = [];

        $behavior = PersistenceEngine::create(
            $this->persistenceId,
            new ShoppingCart(),
            static function (object $state, ActorContext $ctx, object $msg) use (&$states): Effect {
                $states[] = $state;
                if ($msg instanceof AddItem) {
                    return Effect::persist(new ItemAdded($msg->item));
                }

                return Effect::unhandled();
            },
            static function (object $state, object $event): object {
                if ($event instanceof ItemAdded) {
                    return new ShoppingCart([...$state->items, $event->item]);
                }

                return $state;
            },
            $eventStore,
        );

        $cell = $this->createCell($behavior);
        $cell->start();

        $cell->processMessage($this->envelope(new AddItem('apple')));
        $cell->processMessage($this->envelope(new DoNothing())); // unhandled

        // State should still be ['apple'] after unhandled
        self::assertCount(2, $states);
        self::assertSame(['apple'], $states[1]->items);
    }

    // ========================================================================
    // Test: No snapshot store means no snapshots are taken
    // ========================================================================

    #[Test]
    public function no_snapshot_store_skips_snapshotting(): void
    {
        $eventStore = new InMemoryEventStore();

        // Pass a snapshot strategy but no snapshot store
        $behavior = PersistenceEngine::create(
            $this->persistenceId,
            new ShoppingCart(),
            static function (object $state, ActorContext $ctx, object $msg): Effect {
                if ($msg instanceof AddItem) {
                    return Effect::persist(new ItemAdded($msg->item));
                }

                return Effect::none();
            },
            static function (object $state, object $event): object {
                if ($event instanceof ItemAdded) {
                    return new ShoppingCart([...$state->items, $event->item]);
                }

                return $state;
            },
            $eventStore,
            null, // no snapshot store
            SnapshotStrategy::everyN(1), // every event would trigger snapshot if store existed
        );

        $cell = $this->createCell($behavior);
        $cell->start();

        // This should work without errors even though strategy says snapshot every event
        $cell->processMessage($this->envelope(new AddItem('apple')));

        $events = iterator_to_array($eventStore->load($this->persistenceId));
        self::assertCount(1, $events);
    }

    // ========================================================================
    // Test: Full round-trip — persist, stop, recover
    // ========================================================================

    #[Test]
    public function full_round_trip_persist_stop_recover(): void
    {
        $eventStore = new InMemoryEventStore();
        $snapshotStore = new InMemorySnapshotStore();

        // Create first actor instance
        $commandHandler = static function (object $state, ActorContext $ctx, object $msg): Effect {
            if ($msg instanceof AddItem) {
                return Effect::persist(new ItemAdded($msg->item));
            }

            return Effect::none();
        };

        $eventHandler = static function (object $state, object $event): object {
            if ($event instanceof ItemAdded) {
                return new ShoppingCart([...$state->items, $event->item]);
            }

            return $state;
        };

        $behavior1 = PersistenceEngine::create(
            $this->persistenceId,
            new ShoppingCart(),
            $commandHandler,
            $eventHandler,
            $eventStore,
            $snapshotStore,
            SnapshotStrategy::everyN(2),
        );

        $cell1 = $this->createCell($behavior1);
        $cell1->start();

        $cell1->processMessage($this->envelope(new AddItem('apple')));
        $cell1->processMessage($this->envelope(new AddItem('banana')));
        $cell1->processMessage($this->envelope(new AddItem('cherry')));

        // At this point: events 1,2,3 in store; snapshot at seqNr=2
        $cell1->initiateStop();

        // Create second actor instance (simulating recovery)
        $recoveredState = null;
        $commandHandler2 = static function (object $state, ActorContext $ctx, object $msg) use (&$recoveredState): Effect {
            $recoveredState = $state;

            return Effect::none();
        };

        $behavior2 = PersistenceEngine::create(
            $this->persistenceId,
            new ShoppingCart(),
            $commandHandler2,
            $eventHandler,
            $eventStore,
            $snapshotStore,
            SnapshotStrategy::everyN(2),
        );

        $cell2 = $this->createCell($behavior2, '/user/cart-recovered');
        $cell2->start();

        $cell2->processMessage($this->envelope(new DoNothing(), '/user/cart-recovered'));

        // Should recover from snapshot (seqNr=2: ['apple','banana']) + event 3 ('cherry')
        self::assertNotNull($recoveredState);
        self::assertSame(['apple', 'banana', 'cherry'], $recoveredState->items);
    }

    // ========================================================================
    // Pessimistic locking tests
    // ========================================================================

    #[Test]
    public function pessimistic_locking_calls_provider_withLock(): void
    {
        $eventStore = new InMemoryEventStore();
        $lockCalled = false;

        $provider = $this->createMock(PessimisticLockProvider::class);
        $provider->method('withLock')
            ->willReturnCallback(function (PersistenceId $id, Closure $cb) use (&$lockCalled): mixed {
                $lockCalled = true;

                return $cb();
            });

        $behavior = PersistenceEngine::create(
            $this->persistenceId,
            new ShoppingCart(),
            static function (object $state, ActorContext $ctx, object $msg): Effect {
                if ($msg instanceof AddItem) {
                    return Effect::persist(new ItemAdded($msg->item));
                }

                return Effect::none();
            },
            static function (object $state, object $event): object {
                if ($event instanceof ItemAdded) {
                    return new ShoppingCart([...$state->items, $event->item]);
                }

                return $state;
            },
            $eventStore,
            lockingStrategy: LockingStrategy::pessimistic($provider),
        );

        $cell = $this->createCell($behavior);
        $cell->start();

        $cell->processMessage($this->envelope(new AddItem('apple')));

        self::assertTrue($lockCalled);

        $events = iterator_to_array($eventStore->load($this->persistenceId));
        self::assertCount(1, $events);
    }

    #[Test]
    public function pessimistic_locking_refreshes_state_from_store(): void
    {
        $eventStore = new InMemoryEventStore();

        $provider = $this->createMock(PessimisticLockProvider::class);
        $provider->method('withLock')
            ->willReturnCallback(static fn(PersistenceId $id, Closure $cb): mixed => $cb());

        $commandStates = [];

        $behavior = PersistenceEngine::create(
            $this->persistenceId,
            new ShoppingCart(),
            static function (object $state, ActorContext $ctx, object $msg) use (&$commandStates): Effect {
                $commandStates[] = $state;
                if ($msg instanceof AddItem) {
                    return Effect::persist(new ItemAdded($msg->item));
                }

                return Effect::none();
            },
            static function (object $state, object $event): object {
                if ($event instanceof ItemAdded) {
                    return new ShoppingCart([...$state->items, $event->item]);
                }

                return $state;
            },
            $eventStore,
            lockingStrategy: LockingStrategy::pessimistic($provider),
        );

        $cell = $this->createCell($behavior);
        $cell->start();

        // Process first message normally
        $cell->processMessage($this->envelope(new AddItem('apple')));

        // Simulate another process writing to the event store directly
        $eventStore->persist($this->persistenceId, new EventEnvelope(
            persistenceId: $this->persistenceId,
            sequenceNr: 2,
            event: new ItemAdded('banana-from-other-process'),
            eventType: ItemAdded::class,
            timestamp: new DateTimeImmutable(),
        ));

        // Next command should see the refreshed state (including banana)
        $cell->processMessage($this->envelope(new DoNothing()));

        // The command handler should have seen the banana from the other process
        self::assertCount(2, $commandStates);
        self::assertContains('banana-from-other-process', $commandStates[1]->items);
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    private function createCell(mixed $behavior, string $path = '/user/test'): ActorCell
    {
        $actorPath = ActorPath::fromString($path);
        $mailbox = TestMailbox::unbounded();

        /** @var Option<ActorRef<object>> $noParent */
        $noParent = Option::none();

        return new ActorCell(
            $behavior,
            $actorPath,
            $mailbox,
            $this->runtime,
            $noParent,
            SupervisionStrategy::oneForOne(),
            $this->runtime->clock(),
            $this->logger,
            $this->deadLetters,
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
