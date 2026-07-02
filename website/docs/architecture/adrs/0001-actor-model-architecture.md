---
title: "ADR 0001: Actor Model Architecture for PHP"
sidebar_position: 1
related:
  - architecture/design-philosophy
  - core-concepts/actors
  - core-concepts/supervision
  - architecture/adrs/immutable-behavior-api
---

# ADR 0001: Actor Model Architecture for PHP

## Status

Accepted

## Context

PHP lacks a structured concurrency model for building long-running, message-driven systems. Current approaches rely on ad-hoc queue workers, process managers, and manual error recovery. The actor model — proven in Erlang/OTP and Akka — provides a principled foundation: isolated units of computation that communicate only via asynchronous messages, with hierarchical supervision for fault tolerance.

PHP 8.5 introduces features (`readonly class`, pipe operator, `#[NoDiscard]`) that make a type-safe actor implementation viable. The question is whether to build a full actor system or a simpler message-passing library.

## Decision

Build a full actor system with:

- **Actor hierarchy**: Every actor has a parent. The root is the `ActorSystem` guardian.
- **Message-driven**: Actors communicate exclusively via immutable messages through typed `ActorRef<T>`.
- **Location transparency**: `ActorRef` abstracts whether the actor is local or remote.
- **Supervision**: Parent actors define strategies for child failures (restart, stop, escalate).
- **Runtime abstraction**: The actor system is decoupled from the execution engine via a `Runtime` interface, allowing different concurrency backends.

The architecture follows Akka's typed actor model rather than Erlang's process model, because PHP's type system (with Psalm generics) can enforce message type safety at analysis time.

## Consequences

- Actors must be spawned through the system hierarchy (no standalone actors).
- All state is private to the actor; shared mutable state is impossible by design.
- The `Runtime` interface must support scheduling, timers, and mailbox creation.
- Message types must be `readonly class` to prevent accidental mutation.
- Psalm generic templates are required for type-safe `ActorRef<T>` and `Behavior<T>`.
