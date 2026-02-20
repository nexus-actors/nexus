<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Persistence;

use DateTimeImmutable;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Persistence\Event\EventEnvelope;
use Monadial\Nexus\Persistence\Event\InMemoryEventStore;
use Monadial\Nexus\Persistence\EventSourced\Effect;
use Monadial\Nexus\Persistence\EventSourced\EventSourcedBehavior;
use Monadial\Nexus\Persistence\PersistenceId;
use Monadial\Nexus\Persistence\Snapshot\InMemorySnapshotStore;
use Monadial\Nexus\Persistence\Snapshot\SnapshotEnvelope;
use Monadial\Nexus\Persistence\State\DurableEffect;
use Monadial\Nexus\Persistence\State\DurableStateBehavior;
use Monadial\Nexus\Persistence\State\DurableStateEnvelope;
use Monadial\Nexus\Persistence\State\InMemoryDurableStateStore;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use Monadial\Nexus\Tests\Integration\Persistence\Messages\AddItem;
use Monadial\Nexus\Tests\Integration\Persistence\Messages\GetItems;
use Monadial\Nexus\Tests\Integration\Persistence\Messages\GetValue;
use Monadial\Nexus\Tests\Integration\Persistence\Messages\ItemAdded;
use Monadial\Nexus\Tests\Integration\Persistence\Messages\ItemsReply;
use Monadial\Nexus\Tests\Integration\Persistence\Messages\SetValue;
use Monadial\Nexus\Tests\Integration\Persistence\Messages\ShoppingCart;
use Monadial\Nexus\Tests\Integration\Persistence\Messages\ValueReply;
use Monadial\Nexus\Tests\Integration\Persistence\Messages\ValueState;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RecoveryTest extends TestCase
{
    #[Test]
    public function recoverEventSourcedFromEvents(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('recovery-events-test', $runtime);

        $eventStore = new InMemoryEventStore();
        $persistenceId = PersistenceId::of('ShoppingCart', 'cart-recover');

        // Pre-populate the event store with existing events
        $eventStore->persist(
            $persistenceId,
            new EventEnvelope(
                persistenceId: $persistenceId,
                sequenceNr: 1,
                event: new ItemAdded('apple'),
                eventType: ItemAdded::class,
                timestamp: new DateTimeImmutable(),
            ),
            new EventEnvelope(
                persistenceId: $persistenceId,
                sequenceNr: 2,
                event: new ItemAdded('banana'),
                eventType: ItemAdded::class,
                timestamp: new DateTimeImmutable(),
            ),
            new EventEnvelope(
                persistenceId: $persistenceId,
                sequenceNr: 3,
                event: new ItemAdded('cherry'),
                eventType: ItemAdded::class,
                timestamp: new DateTimeImmutable(),
            ),
        );

        // Spawn actor — it should recover from the pre-populated events
        $behavior = EventSourcedBehavior::create(
            $persistenceId,
            new ShoppingCart(),
            static function (ShoppingCart $state, ActorContext $ctx, object $command): Effect {
                if ($command instanceof AddItem) {
                    return Effect::persist(new ItemAdded($command->item));
                }

                if ($command instanceof GetItems) {
                    return Effect::reply($command->replyTo, new ItemsReply($state->items));
                }

                return Effect::none();
            },
            static function (ShoppingCart $state, object $event): ShoppingCart {
                if ($event instanceof ItemAdded) {
                    return new ShoppingCart([...$state->items, $event->item]);
                }

                return $state;
            },
        )
            ->withEventStore($eventStore)
            ->toBehavior();

        $ref = $system->spawn(Props::fromBehavior($behavior), 'cart');

        // Probe to capture the reply
        /** @var list<object> $captured */
        $captured = [];

        /** @var Behavior<object> $probeBehavior */
        $probeBehavior = Behavior::receive(static function (ActorContext $ctx, object $msg) use (&$captured): Behavior {
            $captured[] = $msg;

            return Behavior::same();
        });

        $probeRef = $system->spawn(Props::fromBehavior($probeBehavior), 'probe');

        // Ask for items — should return the recovered state
        $ref->tell(new GetItems($probeRef));

        $runtime->scheduleOnce(Duration::millis(200), static function () use ($system): void {
            $system->shutdown(Duration::seconds(1));
        });

        $system->run();

        self::assertCount(1, $captured);
        self::assertInstanceOf(ItemsReply::class, $captured[0]);
        self::assertSame(['apple', 'banana', 'cherry'], $captured[0]->items);
    }

    #[Test]
    public function recoverEventSourcedFromSnapshotAndEvents(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('recovery-snapshot-test', $runtime);

        $eventStore = new InMemoryEventStore();
        $snapshotStore = new InMemorySnapshotStore();
        $persistenceId = PersistenceId::of('ShoppingCart', 'cart-snap');

        // Pre-populate snapshot at sequenceNr 2 (contains apple, banana)
        $snapshotStore->save($persistenceId, new SnapshotEnvelope(
            persistenceId: $persistenceId,
            sequenceNr: 2,
            state: new ShoppingCart(['apple', 'banana']),
            stateType: ShoppingCart::class,
            timestamp: new DateTimeImmutable(),
        ));

        // Pre-populate events after the snapshot (sequenceNr 3 onward)
        $eventStore->persist(
            $persistenceId,
            new EventEnvelope(
                persistenceId: $persistenceId,
                sequenceNr: 3,
                event: new ItemAdded('cherry'),
                eventType: ItemAdded::class,
                timestamp: new DateTimeImmutable(),
            ),
            new EventEnvelope(
                persistenceId: $persistenceId,
                sequenceNr: 4,
                event: new ItemAdded('date'),
                eventType: ItemAdded::class,
                timestamp: new DateTimeImmutable(),
            ),
        );

        // Spawn actor — should recover from snapshot + replay events
        $behavior = EventSourcedBehavior::create(
            $persistenceId,
            new ShoppingCart(),
            static function (ShoppingCart $state, ActorContext $ctx, object $command): Effect {
                if ($command instanceof AddItem) {
                    return Effect::persist(new ItemAdded($command->item));
                }

                if ($command instanceof GetItems) {
                    return Effect::reply($command->replyTo, new ItemsReply($state->items));
                }

                return Effect::none();
            },
            static function (ShoppingCart $state, object $event): ShoppingCart {
                if ($event instanceof ItemAdded) {
                    return new ShoppingCart([...$state->items, $event->item]);
                }

                return $state;
            },
        )
            ->withEventStore($eventStore)
            ->withSnapshotStore($snapshotStore)
            ->toBehavior();

        $ref = $system->spawn(Props::fromBehavior($behavior), 'cart');

        // Probe to capture the reply
        /** @var list<object> $captured */
        $captured = [];

        /** @var Behavior<object> $probeBehavior */
        $probeBehavior = Behavior::receive(static function (ActorContext $ctx, object $msg) use (&$captured): Behavior {
            $captured[] = $msg;

            return Behavior::same();
        });

        $probeRef = $system->spawn(Props::fromBehavior($probeBehavior), 'probe');

        // Ask for items — should return snapshot state + replayed events
        $ref->tell(new GetItems($probeRef));

        $runtime->scheduleOnce(Duration::millis(200), static function () use ($system): void {
            $system->shutdown(Duration::seconds(1));
        });

        $system->run();

        self::assertCount(1, $captured);
        self::assertInstanceOf(ItemsReply::class, $captured[0]);
        self::assertSame(['apple', 'banana', 'cherry', 'date'], $captured[0]->items);
    }

    #[Test]
    public function recoverDurableStateFromStore(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('recovery-durable-test', $runtime);

        $stateStore = new InMemoryDurableStateStore();
        $persistenceId = PersistenceId::of('ValueHolder', 'val-recover');

        // Pre-populate the durable state store
        $stateStore->upsert($persistenceId, new DurableStateEnvelope(
            persistenceId: $persistenceId,
            version: 5,
            state: new ValueState('recovered-value'),
            stateType: ValueState::class,
            timestamp: new DateTimeImmutable(),
        ));

        // Spawn actor — should recover from pre-populated state
        $behavior = DurableStateBehavior::create(
            $persistenceId,
            new ValueState(),
            static function (ValueState $state, ActorContext $ctx, object $command): DurableEffect {
                if ($command instanceof SetValue) {
                    return DurableEffect::persist(new ValueState($command->value));
                }

                if ($command instanceof GetValue) {
                    return DurableEffect::reply($command->replyTo, new ValueReply($state->value));
                }

                return DurableEffect::none();
            },
        )
            ->withStateStore($stateStore)
            ->toBehavior();

        $ref = $system->spawn(Props::fromBehavior($behavior), 'value-holder');

        // Probe to capture the reply
        /** @var list<object> $captured */
        $captured = [];

        /** @var Behavior<object> $probeBehavior */
        $probeBehavior = Behavior::receive(static function (ActorContext $ctx, object $msg) use (&$captured): Behavior {
            $captured[] = $msg;

            return Behavior::same();
        });

        $probeRef = $system->spawn(Props::fromBehavior($probeBehavior), 'probe');

        // Ask for current value — should return recovered state
        $ref->tell(new GetValue($probeRef));

        $runtime->scheduleOnce(Duration::millis(200), static function () use ($system): void {
            $system->shutdown(Duration::seconds(1));
        });

        $system->run();

        self::assertCount(1, $captured);
        self::assertInstanceOf(ValueReply::class, $captured[0]);
        self::assertSame('recovered-value', $captured[0]->value);

        // Send a new command and verify version continues from recovered state
        $runtime2 = new FiberRuntime();
        $system2 = ActorSystem::create('recovery-durable-test-2', $runtime2);

        $behavior2 = DurableStateBehavior::create(
            $persistenceId,
            new ValueState(),
            static function (ValueState $state, ActorContext $ctx, object $command): DurableEffect {
                if ($command instanceof SetValue) {
                    return DurableEffect::persist(new ValueState($command->value));
                }

                return DurableEffect::none();
            },
        )
            ->withStateStore($stateStore)
            ->toBehavior();

        $ref2 = $system2->spawn(Props::fromBehavior($behavior2), 'value-holder');

        $ref2->tell(new SetValue('updated'));

        $runtime2->scheduleOnce(Duration::millis(200), static function () use ($system2): void {
            $system2->shutdown(Duration::seconds(1));
        });

        $system2->run();

        // Verify the version was incremented from the recovered value (5 -> 6)
        $envelope = $stateStore->get($persistenceId);
        self::assertNotNull($envelope);
        self::assertSame(6, $envelope->version);
        self::assertInstanceOf(ValueState::class, $envelope->state);
        self::assertSame('updated', $envelope->state->value);
    }
}
