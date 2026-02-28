---
sidebar_position: 2
title: Standalone runtime primitives
---

# Standalone runtime primitives

`nexus-runtime` can be used without `ActorSystem` when only async composition,
scheduling, and timeout/cancellation primitives are needed.

## When this is useful

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

## Practical example (no actors)

Use the full standalone examples here:
[Runtime without actors](./runtime-without-actors.md).

That page covers:

- deterministic one-shot orchestration with `StepRuntime`
- timeout/failure mapping via `FutureTimeoutException`
- migration guidance for adopting `nexus-core` later

## Runtime contract

Concrete runtime packages (`runtime-fiber`, `runtime-swoole`, `runtime-step`)
implement `Monadial\Nexus\Runtime\Runtime\Runtime`.

Use this interface when library code should accept runtime implementations
without coupling to actor APIs.
