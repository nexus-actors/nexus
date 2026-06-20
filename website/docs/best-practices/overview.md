---
sidebar_position: 1
title: Best Practices Overview
---

# Best Practices Overview

This section is opinionated. It is the answer to **"how would the
Nexus authors actually build this?"** — not a survey of every option.

We've split it into focused pages that each answer one question. Read
them in order if you're new; jump to the page that matches the
decision you're stuck on if you've used the framework before.

| Page | The question it answers |
|---|---|
| [When to use actors](./when-to-use-actors.md) | *Should I model this as an actor or as a stateless request handler?* |
| [Single-writer aggregates](../guides/single-writer-aggregates.md) | *How do I serialise writes against one entity without locking the DB row?* |
| [Pooled connections behind actors](./pooled-connections.md) | *I have more actors than DB connections — how does that not deadlock?* |
| [Supervision and let-it-crash](./supervision.md) | *How do I handle failures without scattering try/catch everywhere?* |
| [Passivation and memory](./passivation.md) | *What stops a million long-tail actors eating my RAM?* |
| [Ask vs tell](../guides/ask-vs-tell.md) | *When does the handler need to wait for a reply?* |
| [Message design](../guides/message-design.md) | *What makes a good actor message, and what's a trap?* |
| [Scaling out](./scaling.md) | *How do I go from one process to many threads, then many machines?* |
| [Observability](./observability.md) | *What do I actually want to log/meter for an actor system?* |
| [Testing actors](./testing.md) | *How do I write tests that aren't flaky and aren't 200ms each?* |

## The five rules

If you forget everything else, keep these:

1. **One writer per entity, always.** If two actors can write the same
   row, you're back to optimistic-lock-and-pray. Route every command
   for an entity through a single actor (`EntityRefFactory::of($id)`
   gives you exactly this), and writes become serial by construction.

2. **Pool connections; don't pool actors.** Actor spawn is cheap.
   Database connections aren't. When you have more live actors than
   pool slots, use `EntityBehaviorBuilder::withConnectionLifecycle()`
   so each actor borrows on activation and releases on passivation —
   no permanent slot pinning.

3. **Passivate aggressively.** Every entity actor that owns a
   connection (or holds non-trivial state) should set a
   `ReceiveTimeout`. Idle for 60 seconds? Stop. The next message
   spawns a fresh actor that reloads from storage. Hot entities stay
   resident; the long tail evaporates.

4. **Let it crash.** Don't catch exceptions you can't meaningfully
   handle. The supervisor restarts the actor with a fresh mailbox; the
   request that triggered the crash sees a 500/422 mapped from the
   exception type. Defensive `try/catch` around every actor handler
   defeats the whole point.

5. **Ask is sync sugar over tell.** Every `ask()->await()` ties up the
   caller's fiber or coroutine until either the reply or the timeout.
   For fan-out reads, prefer `Future::all([...])`. For fire-and-forget
   side effects, just `tell()` — don't wait for an ACK that doesn't
   add information.

The rest of this section is the detail behind these five.

## Anti-patterns we keep seeing

| Anti-pattern | Why it bites | What to do instead |
|---|---|---|
| One global actor that owns every entity | Single mailbox → single thread → no concurrency | `EntityRefFactory` — one actor per id |
| `EntityBehavior` with `withConnectionSource()` against a `ConnectionPool::take()` | Pool slot pinned forever; pool exhausts after N actors | `withConnectionLifecycle($pool->take(...), $pool->release(...))` |
| Spawning actors per HTTP request without passivation | Memory grows unbounded under traffic | Use `perRequestActor()` (dies with the request) OR set `ReceiveTimeout` |
| `try/catch` around the entire handler | Hides the failure from supervision; corrupt state survives | Let it throw; the supervisor restarts; the framework maps to HTTP status |
| `ask(...)->await()` from inside an actor's receive | Coroutine starvation in the same pool that processes the reply | Use `tell()` and a callback, or refactor the chain |
| Hand-written 503 fallback | Misses partial-failure modes the pool already exposes | Register `PoolExhaustedToServiceUnavailable` once at boot |
| Returning associative arrays from handlers | Drift between read and write shapes; no static check on field names | Typed response DTOs serialised via `JsonResponse::ok($dto)` |

Each of these has a dedicated page in this section explaining why it
bites and what the cleaner pattern looks like in real Nexus code.
