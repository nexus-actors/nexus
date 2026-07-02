---
sidebar_position: 1
title: Best Practices Overview
related:
  - best-practices/when-to-use-actors
  - best-practices/supervision
  - best-practices/passivation
  - best-practices/testing
---

# Best Practices Overview

This section is opinionated: it answers how the Nexus authors would actually build a production actor system, not a survey of every option. Each page addresses one specific decision.

| Page | The question it answers |
|---|---|
| [When to use actors](./when-to-use-actors.md) | Should I model this as an actor or as a stateless request handler? |
| [Pooled connections behind actors](./pooled-connections.md) | I have more actors than DB connections — how does that not deadlock? |
| [Supervision and let-it-crash](./supervision.md) | How do I handle failures without scattering try/catch everywhere? |
| [Passivation and memory](./passivation.md) | What stops a million long-tail actors eating my RAM? |
| [Scaling out](./scaling.md) | How do I go from one process to many threads, then many machines? |
| [Observability](./observability.md) | What do I actually want to log and meter for an actor system? |
| [Testing actors](./testing.md) | How do I write tests that aren't flaky and aren't 200ms each? |
| [Single-writer aggregates](../guides/single-writer-aggregates.md) | How do I serialise writes against one entity without locking the DB row? |
| [Ask vs tell](../guides/ask-vs-tell.md) | When does the handler need to wait for a reply? |
| [Message design](../guides/message-design.md) | What makes a good actor message, and what's a trap? |

## The five rules

If you take nothing else from this section, keep these:

**1. One writer per entity, always.** If two actors can write the same row, you're back to optimistic-lock-and-pray. Route every command for an entity through a single actor — writes become serial by construction.

**2. Pool connections; don't pool actors.** Actor spawn is cheap. Database connections aren't. When you have more live actors than pool slots, use connection lifecycle hooks so each actor borrows on activation and releases on passivation — no permanent slot pinning.

**3. Passivate aggressively.** Every entity actor that owns a connection or holds non-trivial state should set a `ReceiveTimeout`. Idle for 60 seconds? Stop. The next message spawns a fresh actor that reloads from storage. Hot entities stay resident; the long tail evaporates.

**4. Let it crash.** Don't catch exceptions you can't meaningfully handle. The supervisor restarts the actor with a fresh mailbox. Defensive `try/catch` around every actor handler defeats the whole point.

**5. Ask is sync sugar over tell.** Every `ask()->await()` ties up the caller's fiber or coroutine until either the reply or the timeout. For fan-out reads, prefer `Future::all([...])`. For fire-and-forget side effects, call `tell()` — don't wait for an acknowledgement that adds no information.

## Anti-patterns to avoid

| Anti-pattern | Why it bites | What to do instead |
|---|---|---|
| One global actor that owns every entity | Single mailbox → single thread → no concurrency | One actor per id via `EntityRefFactory` |
| `EntityBehavior` with `withConnectionSource()` against a pool | Pool slot pinned forever; pool exhausts after N actors | `withConnectionLifecycle($pool->take(...), $pool->release(...))` |
| Spawning actors per HTTP request without passivation | Memory grows unbounded under traffic | Use `perRequestActor()` (dies with the request) or set `ReceiveTimeout` |
| `try/catch` around the entire handler | Hides the failure from supervision; corrupt state survives | Let it throw; the supervisor restarts; map exceptions to HTTP status codes |
| `ask(...)->await()` from inside an actor's receive | Coroutine starvation in the same pool that processes the reply | Use `tell()` and a callback, or refactor the chain |
| Hand-written 503 fallback | Misses partial-failure modes the pool already exposes | Register `PoolExhaustedToServiceUnavailable` once at boot |
| Returning associative arrays from handlers | Drift between read and write shapes; no static check on field names | Typed response DTOs |

## Next steps

Read the pages in the order they appear in the table above if you're new to Nexus. Jump directly to the page that matches the decision you're facing if you've used the framework before.
