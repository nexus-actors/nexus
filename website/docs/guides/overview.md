---
title: Guides
sidebar_position: 1
related:
  - guides/ask-vs-tell
  - guides/message-design
  - guides/single-writer-aggregates
  - core-concepts/actors
---

# Guides

Cookbook-style recipes for common Nexus patterns. Each page solves one specific problem and assumes you already understand the relevant core concept.

## Available guides

### Current

- [Message design](message-design.md) — how to shape actor messages for safety, extensibility, and type-system coverage.
- [Ask vs tell](ask-vs-tell.md) — when to fire-and-forget with `tell()` and when to wait for a reply with `ask()`.
- [Single-writer aggregates](single-writer-aggregates.md) — how to guarantee that one actor is the sole writer for a given aggregate identity.

### Planned

The following guides are being written as part of the documentation revamp. Each will appear here when complete.

- **Routing patterns** — consistent-hash routing, broadcast, and content-based routing across actor trees.
- **Fan-out and scatter-gather** — splitting work across multiple actors and joining the results.
- **Rate-limiting actor** — implementing a token-bucket rate limiter as a mailbox-backed actor.
- **Saga / process manager** — coordinating multi-step workflows across independent actors with compensating actions.
- **Standalone integration** — embedding a Nexus actor system inside an existing PHP application without full boot.

## Related

- [Core concepts](../core-concepts/actors.md)
- [Best practices](../best-practices/overview.md)
- [Persistence overview](../persistence/overview.md)
