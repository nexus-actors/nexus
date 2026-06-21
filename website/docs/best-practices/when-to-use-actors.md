---
sidebar_position: 2
title: When to use actors
---

# When to use actors

Actors are a hammer. Some of your nails are screws.

## Use an actor when

The decision rule we use: **an actor earns its keep when at least two
of the following are true.**

1. **The thing has identity and durable state.** A user's wallet, a
   chat room, a long-running job, an open WebSocket — something you'd
   look up by id and that needs to survive multiple messages without
   round-tripping the database every time.

2. **Concurrent requests for the same id must be serialised.** Two
   simultaneous deposits to the same wallet, two writes to the same
   chat room. The actor's mailbox enforces this for free; without an
   actor you're holding a row lock, retrying optimistic conflicts, or
   accepting eventual inconsistency you didn't sign up for.

3. **You want a place for `let-it-crash` to happen.** A failure
   restarts one actor; siblings keep going. That's a much smaller
   blast radius than "the request died, restart the worker."

4. **The thing has a lifecycle.** Start, run, idle, stop. Actors model
   PreStart / PostStop / ReceiveTimeout natively. Stateless services
   don't.

5. **You need to fan out and aggregate.** One supervisor parent, N
   workers, results joined. Threads do this; actors model it as
   "spawn, watch, gather."

If you can't tick at least two of those, **don't reach for an actor.**

## Don't use an actor when

- **Pure read paths.** A `GET /wallet/balance` against a denormalised
  read model is a single SELECT. Wrapping it in an actor just adds a
  mailbox between you and the database. The wallet-app's `LedgerHandler`
  is the canonical example — it pulls an `EntityManagerInterface`
  straight from the pool, queries, returns. No actor.
- **Stateless transformations.** A handler that decodes a body,
  validates it, and dispatches it doesn't need an actor.
- **One-shot scripts.** Cron jobs, migrations, CLI tools — actors are
  overkill. Run the script.
- **Cross-cutting concerns.** Logging, metrics, tracing — middleware,
  not actors. (The `nexus-logger` package is itself actor-backed
  internally, but you don't see that.)

## The grid

A simpler way to think about it:

|                          | Stateless | Stateful (per-id) |
|--------------------------|-----------|-------------------|
| **Single writer matters** | (unusual — usually you'd have state too) | **Actor** |
| **Concurrent writes OK**  | Handler / middleware | DB row + optimistic lock |

The bottom-right cell is the cell where you *can* skip actors and rely
on the database — but you're now responsible for retry, conflict
detection, side-effect idempotency, and recovery semantics. The actor
gives you all of that as a side effect of having one mailbox.

## How small should an actor be?

> **One actor per business concept, scoped by the natural unit of
> consistency.**

For a wallet: one actor per owner. For a chat: one actor per room. For
a long-running job: one actor per job id. NOT one actor per user (the
user has many wallets), NOT one actor per message (the room is the
consistency unit, not the line).

If you're unsure of the boundary, ask: *which set of operations must
happen in a defined order to keep the data sane?* That set is the
actor.

## Reading the rest

Once you've decided "yes, this needs an actor," the follow-up
questions are downstream:

- **Where does the state live between messages?** → [Single-writer
  aggregates](../guides/single-writer-aggregates.md)
- **What happens when the actor crashes?** → [Supervision and
  let-it-crash](./supervision.md)
- **What happens when the actor goes idle?** → [Passivation and
  memory](./passivation.md)
- **Do callers wait for an answer?** → [Ask vs tell](../guides/ask-vs-tell.md)
