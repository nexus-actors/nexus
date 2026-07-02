---
sidebar_position: 1
title: Examples Overview
related:
  - tutorials/wallet-app
  - tutorials/tictactoe
  - getting-started/quick-start
  - core-concepts/behaviors
  - scaling/overview
---

# Examples

This is a curated list of runnable Nexus applications. Each example lives in the `examples/` folder of the repo and is wired into the same `docker-compose` workflow as the framework itself — boot it, hit the endpoints, read the logs, and modify it locally without touching your host PHP.

Each example demonstrates a specific combination of patterns, not just a single feature. After reading two or three, you have a mental library of what an actor system shaped like that looks like in PHP.

## What the examples are for

Examples are not tutorials and they are not reference manuals. They are concrete answers to questions you have probably already asked if you have worked with PHP HTTP services:

- *"How do I serialise per-aggregate writes without a global lock?"* — see the wallet-app's `LedgerActor`.
- *"What does a multi-thread Swoole boot look like end-to-end?"* — see the wallet-app's `Boot/` tree.
- *"How do I borrow a pooled DB connection from a request without leaking it on a 404?"* — see the wallet-app's middleware order and `ConnectionScopeMiddleware`.
- *"How is the actor system shut down cleanly when the container gets SIGTERM?"* — see the wallet-app's `SwooleThreadServer` integration and the [Swoole runtime page](../runtimes/swoole.md).

## The current list

| Example | What it shows | Key features |
|---|---|---|
| [Wallet app](./wallet-app.md) | Multi-thread Swoole HTTP server, event-sourced wallet aggregates, per-owner Doctrine ledger writer, raw DBAL admin endpoint, graceful shutdown. | Actor-per-owner, EntityBehavior, ConnectionPool, EntityManagerPool, `#[Transactional]`, NexusLogger, single-writer guarantee, idle passivation, supervised restart. |
| [Tic-tac-toe](./tictactoe.md) | Multiplayer WebSocket game session per game id, React SPA client, lobby over REST, snapshot broadcast to all attached sockets. | EntityBehavior aggregate, WebSocketChannelActor fan-out, sealed command interface, per-id single-writer, passivation on idle, worker-pool sharding. |

## Reading order

Read examples in the order they appear above. The wallet-app is intentionally the densest — it touches almost every Nexus subsystem — and later examples will assume you have at least skimmed it. Tic-tac-toe layers WebSocket broadcast on top of the same `EntityBehavior` pattern, so read wallet-app first.

If you are here to copy a pattern, the "Key features" column is the index: pick the example whose features include what you need.

## Running an example

Every example has its own `compose.yaml` and is self-contained:

```bash
cd examples/<name>
docker compose up -d
docker compose logs -f app
docker compose down
```

The Composer dependencies under `examples/<name>` reference the in-repo packages so you are running the exact same source tree as your local working copy. Edit a file under `packages/` and restart the example container to see the change.

## Next steps

- [Wallet app](./wallet-app.md) — the full multi-subsystem example.
- [Tic-tac-toe](./tictactoe.md) — WebSocket multiplayer with per-game single-writer.
- [Quick Start](../getting-started/quick-start.md) — build a counter actor from scratch.
- [Behaviors](../core-concepts/behaviors.md) — understand the core behavior model the examples rely on.
