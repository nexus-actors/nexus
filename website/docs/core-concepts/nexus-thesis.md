---
sidebar_position: 1
title: Nexus thesis
---

# Nexus thesis

Nexus exists to make concurrent, long-running, fault-tolerant PHP systems practical without changing languages.  
It is opinionated: actors, supervision, immutable messages, and explicit runtime choices over ad-hoc workers and shared mutable state.

## When Nexus is the right tool

Nexus fits well when the system needs:

- Many independent stateful units running concurrently (sessions, devices, workflows, entities)
- Clear failure boundaries and automatic recovery through supervision
- Message-driven workflows where ordering and isolation matter
- Long-lived processes, not only request/response lifecycles
- Type-safe message contracts enforced during static analysis

Typical fits:

- Event-driven services and orchestration engines
- Real-time pipelines and stream processors
- Stateful integrations (payments, fulfillment, IoT, game/session backends)
- Domain models where each aggregate/entity maps naturally to an actor

## When Nexus is not the right tool

Nexus is a poor fit when the workload is primarily:

- Simple CRUD over HTTP with short-lived request handlers
- Batch jobs with little state and low coordination needs
- Small apps where queue workers + cron already solve the problem
- Teams that cannot operate long-running processes yet

When concurrency and failure semantics are simple, actors add unnecessary complexity.

## Tradeoffs we choose deliberately

Nexus intentionally prefers:

- **Isolation over shared state**: fewer race conditions, more explicit message flow
- **Supervision over scattered try/catch retries**: centralized failure policy
- **Typed protocols over dynamic dispatch**: earlier feedback, stricter design discipline
- **Runtime abstraction over single-runtime lock-in**: portability between Fiber, Swoole, and Step
- **Deterministic testability over hidden scheduler behavior**: Step runtime and virtual time

These choices improve correctness and operability, but they require stronger message design and explicit lifecycle thinking.

## Runtime choice in practice

- **Fiber runtime**: best default for local development and CI without extensions.
- **Swoole runtime**: best for production-scale concurrency and async I/O workloads.
- **Step runtime**: best for deterministic tests where message order and virtual time must be exact.

## Non-goals

Nexus is not trying to be:

- A universal replacement for Laravel/Symfony app architecture
- A transparent distributed system that hides all network realities
- A silver bullet for every asynchronous workload

It is a focused foundation for actor-based systems in PHP.

## Practical decision checklist

Nexus is likely a good fit if most answers are "yes":

- Do we have concurrency bugs or coordination complexity today?
- Do we need explicit recovery strategy per failure mode?
- Do we benefit from isolating state per entity/workflow?
- Are we willing to design explicit message protocols?
- Can we operate long-running services with metrics and alerts?

If most answers are "no", prefer simpler architecture first.
