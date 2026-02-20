<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Persistence;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Persistence\Event\InMemoryEventStore;
use Monadial\Nexus\Persistence\EventSourced\AbstractEventSourcedActor;
use Monadial\Nexus\Persistence\EventSourced\Effect;
use Monadial\Nexus\Persistence\EventSourced\EventSourcedBehavior;
use Monadial\Nexus\Persistence\PersistenceId;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use Monadial\Nexus\Tests\Integration\Persistence\Messages\AddItem;
use Monadial\Nexus\Tests\Integration\Persistence\Messages\GetItems;
use Monadial\Nexus\Tests\Integration\Persistence\Messages\ItemAdded;
use Monadial\Nexus\Tests\Integration\Persistence\Messages\ItemsReply;
use Monadial\Nexus\Tests\Integration\Persistence\Messages\ShoppingCart;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EventSourcedActorTest extends TestCase
{
    #[Test]
    public function spawnAndSendCommands(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('es-functional-test', $runtime);

        $eventStore = new InMemoryEventStore();
        $persistenceId = PersistenceId::of('ShoppingCart', 'cart-1');

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

        // Send AddItem commands
        $ref->tell(new AddItem('apple'));
        $ref->tell(new AddItem('banana'));
        $ref->tell(new AddItem('cherry'));

        // Probe actor to capture replies
        /** @var list<object> $captured */
        $captured = [];

        /** @var Behavior<object> $probeBehavior */
        $probeBehavior = Behavior::receive(static function (ActorContext $ctx, object $msg) use (&$captured): Behavior {
            $captured[] = $msg;

            return Behavior::same();
        });

        $probeRef = $system->spawn(Props::fromBehavior($probeBehavior), 'probe');

        // Ask for items via probe
        $ref->tell(new GetItems($probeRef));

        // Schedule shutdown
        $runtime->scheduleOnce(Duration::millis(200), static function () use ($system): void {
            $system->shutdown(Duration::seconds(1));
        });

        $system->run();

        // Verify reply contains all items
        self::assertCount(1, $captured);
        self::assertInstanceOf(ItemsReply::class, $captured[0]);
        self::assertSame(['apple', 'banana', 'cherry'], $captured[0]->items);

        // Verify events were persisted in the event store
        $events = iterator_to_array($eventStore->load($persistenceId));
        self::assertCount(3, $events);
        self::assertInstanceOf(ItemAdded::class, $events[0]->event);
        self::assertSame('apple', $events[0]->event->item);
        self::assertSame(1, $events[0]->sequenceNr);
        self::assertInstanceOf(ItemAdded::class, $events[1]->event);
        self::assertSame('banana', $events[1]->event->item);
        self::assertSame(2, $events[1]->sequenceNr);
        self::assertInstanceOf(ItemAdded::class, $events[2]->event);
        self::assertSame('cherry', $events[2]->event->item);
        self::assertSame(3, $events[2]->sequenceNr);
    }

    #[Test]
    public function classBasedEventSourcedActor(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('es-class-test', $runtime);

        $eventStore = new InMemoryEventStore();

        $actor = new class ($eventStore) extends AbstractEventSourcedActor {
            public function __construct(InMemoryEventStore $eventStore)
            {
                parent::__construct($eventStore);
            }

            public function persistenceId(): PersistenceId
            {
                return PersistenceId::of('ShoppingCart', 'cart-class');
            }

            public function emptyState(): object
            {
                return new ShoppingCart();
            }

            public function handleCommand(object $state, ActorContext $ctx, object $command): Effect
            {
                if ($command instanceof AddItem) {
                    return Effect::persist(new ItemAdded($command->item));
                }

                if ($command instanceof GetItems) {
                    return Effect::reply($command->replyTo, new ItemsReply($state->items));
                }

                return Effect::none();
            }

            public function applyEvent(object $state, object $event): object
            {
                if ($event instanceof ItemAdded) {
                    return new ShoppingCart([...$state->items, $event->item]);
                }

                return $state;
            }
        };

        $ref = $system->spawn($actor->toProps(), 'cart');

        $ref->tell(new AddItem('milk'));
        $ref->tell(new AddItem('bread'));

        /** @var list<object> $captured */
        $captured = [];

        /** @var Behavior<object> $probeBehavior */
        $probeBehavior = Behavior::receive(static function (ActorContext $ctx, object $msg) use (&$captured): Behavior {
            $captured[] = $msg;

            return Behavior::same();
        });

        $probeRef = $system->spawn(Props::fromBehavior($probeBehavior), 'probe');

        $ref->tell(new GetItems($probeRef));

        $runtime->scheduleOnce(Duration::millis(200), static function () use ($system): void {
            $system->shutdown(Duration::seconds(1));
        });

        $system->run();

        self::assertCount(1, $captured);
        self::assertInstanceOf(ItemsReply::class, $captured[0]);
        self::assertSame(['milk', 'bread'], $captured[0]->items);

        // Verify events in store
        $persistenceId = PersistenceId::of('ShoppingCart', 'cart-class');
        $events = iterator_to_array($eventStore->load($persistenceId));
        self::assertCount(2, $events);
        self::assertSame('milk', $events[0]->event->item);
        self::assertSame('bread', $events[1]->event->item);
    }

    #[Test]
    public function replyViaEffect(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('es-reply-test', $runtime);

        $eventStore = new InMemoryEventStore();
        $persistenceId = PersistenceId::of('ShoppingCart', 'cart-reply');

        $behavior = EventSourcedBehavior::create(
            $persistenceId,
            new ShoppingCart(),
            static function (ShoppingCart $state, ActorContext $ctx, object $command): Effect {
                if ($command instanceof AddItem && $command->item === 'with-reply') {
                    // Use thenReply to send reply after persist
                    return Effect::persist(new ItemAdded('with-reply'))
                        ->thenReply($ctx->self(), static function (ShoppingCart $newState): ItemsReply {
                            return new ItemsReply($newState->items);
                        });
                }

                if ($command instanceof AddItem) {
                    return Effect::persist(new ItemAdded($command->item));
                }

                if ($command instanceof ItemsReply) {
                    // This will be received as a self-reply — capture for assertion via probe
                    return Effect::none();
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

        // We need a wrapper that captures the thenReply result.
        // The thenReply sends to ctx->self(), so we need a probe instead.
        // Let's use a probe-based approach.

        /** @var list<object> $captured */
        $captured = [];

        // Build a behavior that wraps the event-sourced one with probe capture
        $persistenceId2 = PersistenceId::of('ShoppingCart', 'cart-reply2');

        $behavior2 = EventSourcedBehavior::create(
            $persistenceId2,
            new ShoppingCart(),
            static function (ShoppingCart $state, ActorContext $ctx, object $command) use (&$captured): Effect {
                if ($command instanceof AddItem) {
                    return Effect::persist(new ItemAdded($command->item))
                        ->thenReply($ctx->self(), static function (ShoppingCart $newState): ItemsReply {
                            return new ItemsReply($newState->items);
                        });
                }

                if ($command instanceof ItemsReply) {
                    $captured[] = $command;

                    return Effect::none();
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

        $ref = $system->spawn(Props::fromBehavior($behavior2), 'cart-reply');

        $ref->tell(new AddItem('apple'));
        $ref->tell(new AddItem('banana'));

        $runtime->scheduleOnce(Duration::millis(200), static function () use ($system): void {
            $system->shutdown(Duration::seconds(1));
        });

        $system->run();

        // The thenReply sends back to self, which is then received as ItemsReply command
        self::assertCount(2, $captured);

        self::assertInstanceOf(ItemsReply::class, $captured[0]);
        self::assertSame(['apple'], $captured[0]->items);

        self::assertInstanceOf(ItemsReply::class, $captured[1]);
        self::assertSame(['apple', 'banana'], $captured[1]->items);
    }
}
