<?php

declare(strict_types=1);

namespace App\Persistence;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Persistence\EventSourced\Effect;
use Monadial\Nexus\Persistence\EventSourced\EventSourcedBehavior;
use Monadial\Nexus\Persistence\PersistenceId;
use Monadial\Nexus\Persistence\Event\EventStore;

/**
 * Example event-sourced actor.
 *
 * Replace the command/event types with your own domain types.
 * Wire an EventStore before calling behavior() — e.g. DbalEventStore or DoctrineEventStore.
 * See https://docs.nexusactors.com/persistence/event-sourcing
 */
final class ExampleStateActor
{
    public static function behavior(PersistenceId $id, EventStore $eventStore): Behavior
    {
        return EventSourcedBehavior::create(
            persistenceId: $id,
            emptyState: new ExampleState(),
            commandHandler: static function (ExampleState $state, ActorContext $ctx, object $command): Effect {
                return Effect::none();
            },
            eventHandler: static function (ExampleState $state, object $event): ExampleState {
                return $state;
            },
        )->withEventStore($eventStore)->toBehavior();
    }
}

/**
 * Empty aggregate state for the example actor.
 *
 * Replace with your own state class carrying the domain fields you need.
 */
final readonly class ExampleState
{
    public function __construct(
        public int $counter = 0,
    ) {}
}
