---
title: DurableStateBehavior
sidebar_position: 10
related:
  - core-concepts/persistence
  - reference/classes/persistence-id
  - reference/classes/event-sourced-behavior
  - reference/classes/behavior
---

# DurableStateBehavior

Fluent builder for durable-state actor behaviors — persists the full current state as a single snapshot on every write.

## What it does

`DurableStateBehavior` is the simpler persistence model: rather than storing a sequence of domain events, it saves the actor's complete current state on each `DurableEffect::persist()` call. There is no event replay on startup — the `DurableStateEngine` loads the latest snapshot from the `DurableStateStore` and delivers it as the initial state before the first user command arrives. This trades event-history auditability for lower storage overhead and much faster recovery. Use `DurableStateBehavior` for read-model projections, session state, configuration caches, and any entity where an event log would add cost without adding value.

## Example

```php title="src/UserProfileActor.php"
use Monadial\Nexus\Persistence\PersistenceId;
use Monadial\Nexus\Persistence\State\DurableStateBehavior;
use Monadial\Nexus\Persistence\State\DurableEffect;

$behavior = DurableStateBehavior::create(
    PersistenceId::of('UserProfile', $userId),
    new UserProfile(),
    static fn (ActorContext $ctx, object $cmd, UserProfile $state): DurableEffect => match (true) {
        $cmd instanceof UpdateEmail   => DurableEffect::persist($state->withEmail($cmd->email)),
        $cmd instanceof UpdateName    => DurableEffect::persist($state->withName($cmd->name)),
        $cmd instanceof GetProfile    => DurableEffect::none()
            ->thenReply($ctx->sender(), fn (UserProfile $s) => $s),
        $cmd instanceof DeleteProfile => DurableEffect::persist(UserProfile::deleted())
            ->thenStop(),
        default => DurableEffect::none(),
    },
)
    ->withStateStore($stateStore)
    ->toBehavior();
```

## Key methods

- `DurableStateBehavior::create(PersistenceId, object $emptyState, Closure $commandHandler): self` — entry point; the three required arguments define the full durable-state contract.
- `->withStateStore(DurableStateStore $store): self` — required; the backing store for loading and persisting full state snapshots.
- `->withWriterId(Ulid $writerId): self` — override the ULID stamped on persisted envelopes; useful for deterministic tests or data-migration scenarios.
- `->toBehavior(): Behavior` — compile everything into a `Behavior` ready to pass to `Props::fromBehavior()`; throws `LogicException` if `withStateStore()` was not called.

## Full API reference

[Full method list and class hierarchy](https://api.nexusactors.com/classes/Monadial-Nexus-Persistence-State-DurableStateBehavior.html)

## See also

- [Persistence concept](../../core-concepts/persistence) — durable state model, recovery pipeline, and comparison with event sourcing
- [PersistenceId](persistence-id) — how to construct the stable identity used as the primary key
- [EventSourcedBehavior](event-sourced-behavior) — event-sourced alternative with full history and snapshot strategies
- [Behavior](behavior) — the result type returned by `toBehavior()`
