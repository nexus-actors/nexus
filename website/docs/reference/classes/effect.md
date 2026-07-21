---
title: Effect
sidebar_position: 11
related:
  - persistence/overview
  - reference/classes/event-sourced-behavior
  - reference/classes/persistence-id
  - reference/classes/behavior
---

# Effect

The return type from a command handler in an event-sourced actor — encodes what the actor intends to do next.

## What it does

`Effect` is an immutable value that a command handler returns to the `PersistenceEngine` instead of mutating state directly. It separates *intent* from *execution*: the handler declares which events to persist, whether to stash or stop, or whether to reply to the sender — and the engine carries out those intentions in the correct order (persist → apply event handler → run side effects).

The most common variant is `Effect::persist(...$events)`, which queues one or more domain events for storage and then feeds them through the event handler to derive the next state. Additional intents are chained as side-effect callbacks via `->thenRun()` and `->thenReply()`. On the persist path they receive the *post-persist* state, ensuring replies carry fresh data; on any other effect (including `Effect::none()`) they run after the effect's primary action and receive the unchanged current state.

When no state change is needed — for example, a read query — reply with `Effect::reply($to, $message)`; a bare `Effect::none()` acknowledges the command without persisting, and `Effect::none()->thenReply(...)` replies with the current state — the canonical read-only query. To forward a message to the stash buffer during recovery, return `Effect::stash()`. To terminate the actor cleanly, return `Effect::stop()`.

## Example

```php title="src/LedgerActor.php"
use Monadial\Nexus\Persistence\EventSourced\Effect;

// Inside an EventSourcedBehavior command handler:
static fn (LedgerState $state, ActorContext $ctx, object $cmd): Effect => match (true) {
    $cmd instanceof Credit => Effect::persist(new Credited($cmd->amount, $cmd->currency)),

    $cmd instanceof Debit  => $state->balance >= $cmd->amount
        ? Effect::persist(new Debited($cmd->amount, $cmd->currency))
            ->thenReply($cmd->replyTo, fn (LedgerState $s) => new DebitAccepted($s->balance))
        : Effect::reply($cmd->replyTo, new InsufficientFunds($state->balance)),

    $cmd instanceof GetBalance => Effect::reply($cmd->replyTo, new BalanceResponse($state->balance)),

    $cmd instanceof Close => Effect::persist(new AccountClosed())
        ->thenRun(fn (LedgerState $s) => $ctx->log()->info('Ledger closed', ['id' => (string) $s->id])),

    default => Effect::unhandled(),
},
```

## Key methods

- `Effect::persist(object ...$events): self` — persist one or more domain events; the event handler is called for each before side effects run.
- `Effect::none(): self` — no events, no state change; chained side effects run with the unchanged current state.
- `Effect::reply(ActorRef<object> $to, object $message): self` — send an immediate reply without persisting (useful for read queries).
- `Effect::stash(): self` — defer the current command until the actor is ready (used during recovery or initialization).
- `Effect::stop(): self` — stop the actor cleanly after any chained side effects complete.
- `Effect::unhandled(): self` — signal that the command was not recognised; routes to dead letters.
- `->thenReply(ActorRef<object> $to, Closure $fn): self` — send a reply after the effect's primary action; `$fn` receives the post-persist state on the persist path, the unchanged current state on any other effect.
- `->thenRun(Closure $fn): self` — execute an arbitrary side effect after the effect's primary action; `$fn` receives the post-persist state on the persist path, the unchanged current state on any other effect. Never re-executed during recovery.

## Full API reference

[Full method list and class hierarchy](https://api.nexusactors.com/classes/Monadial-Nexus-Persistence-EventSourced-Effect.html)

## See also

- [Persistence concept](../../persistence/overview) — event sourcing model, recovery pipeline, and snapshot strategies
- [EventSourcedBehavior](event-sourced-behavior) — the fluent builder that wires up command and event handlers
- [PersistenceId](persistence-id) — the stable identity used as the primary key in the event store
- [Behavior](behavior) — the actor behavior model that persistence builds on
