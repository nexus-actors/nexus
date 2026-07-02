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

The most common variant is `Effect::persist(...$events)`, which queues one or more domain events for storage and then feeds them through the event handler to derive the next state. Additional intents are chained as side-effect callbacks via `->thenRun()` and `->thenReply()`, which receive the *post-persist* state, ensuring replies carry fresh data.

When no state change is needed — for example, a read query — return `Effect::none()`. To forward a message to the stash buffer during recovery, return `Effect::stash()`. To terminate the actor cleanly after persisting, chain `Effect::persist(...)->thenRun(fn() => ...)` or simply return `Effect::stop()`.

## Example

```php title="src/LedgerActor.php"
use Monadial\Nexus\Persistence\EventSourced\Effect;

// Inside an EventSourcedBehavior command handler:
static fn (ActorContext $ctx, object $cmd, LedgerState $state): Effect => match (true) {
    $cmd instanceof Credit => Effect::persist(new Credited($cmd->amount, $cmd->currency)),

    $cmd instanceof Debit  => $state->balance >= $cmd->amount
        ? Effect::persist(new Debited($cmd->amount, $cmd->currency))
            ->thenReply($cmd->replyTo, fn (LedgerState $s) => new DebitAccepted($s->balance))
        : Effect::reply($cmd->replyTo, new InsufficientFunds($state->balance)),

    $cmd instanceof GetBalance => Effect::none()
        ->thenReply($cmd->replyTo, fn (LedgerState $s) => new BalanceResponse($s->balance)),

    $cmd instanceof Close => Effect::persist(new AccountClosed())
        ->thenRun(fn (LedgerState $s) => $ctx->log()->info('Ledger closed', ['id' => (string) $s->id])),

    default => Effect::unhandled(),
},
```

## Key methods

- `Effect::persist(object ...$events): self` — persist one or more domain events; the event handler is called for each before side effects run.
- `Effect::none(): self` — no events, no state change; side effects may still be attached.
- `Effect::reply(ActorRef $to, object $message): self` — send an immediate reply without persisting (useful for read queries).
- `Effect::stash(): self` — defer the current command until the actor is ready (used during recovery or initialization).
- `Effect::stop(): self` — stop the actor cleanly after any chained side effects complete.
- `Effect::unhandled(): self` — signal that the command was not recognised; routes to dead letters.
- `->thenReply(ActorRef $to, Closure $fn): self` — send a reply after events are persisted; `$fn` receives the post-persist state.
- `->thenRun(Closure $fn): self` — execute an arbitrary side effect after events are persisted; `$fn` receives the post-persist state.

## Full API reference

[Full method list and class hierarchy](https://api.nexusactors.com/classes/Monadial-Nexus-Persistence-EventSourced-Effect.html)

## See also

- [Persistence concept](../../persistence/overview) — event sourcing model, recovery pipeline, and snapshot strategies
- [EventSourcedBehavior](event-sourced-behavior) — the fluent builder that wires up command and event handlers
- [PersistenceId](persistence-id) — the stable identity used as the primary key in the event store
- [Behavior](behavior) — the actor behavior model that persistence builds on
