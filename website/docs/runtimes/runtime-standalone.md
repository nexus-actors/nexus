---
sidebar_position: 2
title: Standalone Runtime Primitives
---

# Standalone Runtime Primitives

`nexus-runtime` can be used without `ActorSystem` when you only need async
composition, scheduling, and timeout/cancellation primitives.

## When This Is Useful

- building framework adapters around callback/timer APIs
- orchestrating background workflows without actor hierarchy
- sharing `Duration` + `Future` abstractions across packages
- writing deterministic tests with `StepRuntime` and `VirtualClock`

## Install

```bash
# Runtime contracts + Future primitives
composer require nexus-actors/runtime

# Optional deterministic runtime implementation for tests
composer require --dev nexus-actors/runtime-step
```

## Practical Example (No Actors)

Use the full standalone examples here:
[Runtime Without Actors](./runtime-without-actors.md).

That page covers:

- deterministic one-shot orchestration with `StepRuntime`
- timeout/failure mapping via `FutureTimeoutException`
- migration guidance for adopting `nexus-core` later

## Runtime Contract

Concrete runtime packages (`runtime-fiber`, `runtime-swoole`, `runtime-step`)
implement `Monadial\Nexus\Runtime\Runtime\Runtime`.

Use this when your code should accept runtime implementations without coupling
to actor APIs.
