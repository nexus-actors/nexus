---
sidebar_position: 2
title: When to use actors
related:
  - core-concepts/actors
  - best-practices/supervision
  - best-practices/passivation
  - guides/ask-vs-tell
---

# When to use actors

Actors are a hammer. Some of your nails are screws.

## Use an actor when

An actor earns its keep when **at least two** of the following are true:

1. **The thing has identity and durable state.** A user's wallet, a chat room, a long-running job, an open WebSocket — something you'd look up by id that needs to survive multiple messages without round-tripping the database every time.

2. **Concurrent requests for the same id must be serialised.** Two simultaneous deposits to the same wallet, two writes to the same chat room. The actor's mailbox enforces this for free; without an actor you're holding a row lock, retrying optimistic conflicts, or accepting eventual inconsistency.

3. **You want a contained failure boundary.** A failure restarts one actor; siblings keep going. That's a much smaller blast radius than "the request died, restart the worker."

4. **The thing has a lifecycle.** Start, run, idle, stop. Actors model `PreStart` / `PostStop` / `ReceiveTimeout` natively. Stateless services don't.

5. **You need to fan out and aggregate.** One supervisor, N workers, results joined. Actors model this as spawn, watch, gather.

If you can't tick at least two of those, don't reach for an actor.

## Don't use an actor when

Actors add a mailbox between the caller and the work. In these situations, that indirection costs more than it returns:

- **Pure read paths.** A `GET /wallet/balance` against a denormalised read model is a single SELECT. Wrapping it in an actor adds latency, not safety.
- **Stateless transformations.** A handler that decodes a body, validates it, and dispatches a command doesn't need an actor.
- **One-shot scripts.** Cron jobs, migrations, CLI tools — run the script.
- **Cross-cutting concerns.** Logging, metrics, tracing — use middleware, not actors.

## The decision grid

|                          | Stateless | Stateful (per-id) |
|--------------------------|-----------|-------------------|
| **Single writer matters** | Unusual — you'd typically have state too | **Actor** |
| **Concurrent writes OK**  | Handler or middleware | DB row + optimistic lock |

The bottom-right cell is where you can skip actors and rely on the database — but you then own retry, conflict detection, side-effect idempotency, and recovery semantics. The actor gives you all of that as a side effect of having one mailbox.

## How small should an actor be?

**One actor per business concept, scoped by the natural unit of consistency.**

For a wallet: one actor per owner. For a chat room: one actor per room. For a long-running job: one actor per job id. Not one actor per user (a user has many wallets), not one actor per message (the room is the consistency unit, not the line).

If you're unsure of the boundary, ask: which set of operations must happen in a defined order to keep the data sane? That set is the actor.

## Next steps

Once you've decided an actor is the right tool, the follow-up questions are:

- **Where does state live between messages?** → [Single-writer aggregates](../guides/single-writer-aggregates.md)
- **What happens when the actor crashes?** → [Supervision and let-it-crash](./supervision.md)
- **What happens when the actor goes idle?** → [Passivation and memory](./passivation.md)
- **Do callers wait for an answer?** → [Ask vs tell](../guides/ask-vs-tell.md)
