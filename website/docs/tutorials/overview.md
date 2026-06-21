---
sidebar_position: 1
title: Examples Overview
---

# Examples

This is a curated list of runnable Nexus applications. Each example is in
the `examples/` folder of the repo and is wired into the same
`docker-compose` workflow as the framework itself — so you can boot
them, hit the endpoints, read the logs, and modify them locally without
touching your host PHP.

Each example was picked to illustrate a specific *combination of
patterns* — not just "how to use feature X." The goal is that, after
reading two or three, you have a mental library of "this is what an
actor system shaped like *that* looks like in PHP."

## What the examples are for

Examples aren't tutorials and they aren't reference manuals. They are
**concrete answers** to questions you've probably already asked
yourself if you've worked with PHP HTTP services for any length of
time:

- *"How do I actually serialise per-aggregate writes without a global
  lock?"* — see the wallet-app's `LedgerActor`.
- *"What does a multi-thread Swoole boot look like end-to-end?"* —
  see the wallet-app's `Boot/` tree.
- *"How do I borrow a pooled DB connection from a request without
  leaking it on a 404?"* — see the wallet-app's middleware order
  + `ConnectionScopeMiddleware`.
- *"How is the actor system shut down cleanly when the container gets
  SIGTERM?"* — see the wallet-app's `SwooleThreadServer` integration
  and the [Swoole runtime page](../runtimes/swoole.md).

If you skim only one section of these docs, this is the one to skim.
Patterns become real when you can press them.

## The current list

| Example | What it shows | Key features |
|---|---|---|
| [Wallet app](./wallet-app.md) | Multi-thread Swoole HTTP server, event-sourced wallet aggregates, per-owner Doctrine ledger writer, raw DBAL admin endpoint, graceful shutdown. | Actor-per-owner, EntityBehavior, ConnectionPool, EntityManagerPool, `#[Transactional]`, NexusLogger, single-writer guarantee, idle passivation, supervised restart. |

More examples will land in this list as they're written; each one
ships with its own page following the same structure (motivation →
architecture → boot → run it).

## Reading order

If this is your first time, read them in the order they appear above.
The wallet-app is intentionally the densest — it touches almost every
Nexus subsystem — but every later example will assume you've at least
skimmed it.

If you're here to *copy a pattern*, the per-example "Key features"
column above is the index: pick the example whose features include
what you need.

## Running an example

Every example has its own `docker-compose.yml` and is self-contained.
The standard flow is:

```bash
cd examples/<name>
docker compose up -d
# hit the endpoints
docker compose logs -f app
docker compose down
```

The composer dependencies under `examples/<name>` reference the
in-repo packages (`/nexus-packages/...`) so you're running the
**exact same source tree** as your local working copy. Edit a file
under `packages/` and restart the example container to see the
change.
