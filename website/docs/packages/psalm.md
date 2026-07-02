---
sidebar_position: 5
title: nexus-psalm
related:
  - packages/core
  - core-concepts/actors
  - core-concepts/behaviors
---

# nexus-psalm

Psalm plugin that enforces actor-model safety rules and improves generic type inference for Nexus APIs.

## What's in this package

**Safety rules** (catch violations at analysis time, before runtime)

- `NonReadonlyMessage` — `tell()` arguments must be `readonly` classes
- `MutableActorState` — `ActorHandler` / `StatefulActorHandler` properties must be `readonly`
- `NonSerializableRemoteMessage` — `WorkerActorRef::tell()` messages must carry `#[MessageType]`
- `BlockingCallInHandler` — flags `sleep`, `file_get_contents`, `curl_exec`, and similar blocking calls inside handlers
- `MutableClosureCapture` — `Props::fromFactory()` / `fromStatefulFactory()` closures must not capture by reference (`use (&$var)`)

**Type providers**

- `PropsReturnTypeProvider` — infers `Props<T>` from `fromContainer`, `fromFactory`, and `fromStatefulFactory` so downstream `spawn()` returns the correct `ActorRef<T>`

**Narrowing hook**

- `BehaviorSubclassNarrowingHook` — suppresses false-positive `DocblockTypeContradiction` / `TypeDoesNotContainType` errors when `instanceof`-narrowing `Behavior<T>` to a concrete subclass

## Install

```bash
composer require --dev nexus-actors/psalm
```

## Quick example

```xml title="psalm.xml"
<?xml version="1.0"?>
<psalm errorLevel="1" resolveFromConfigFile="true"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xmlns="https://getpsalm.org/schema/config"
    xsi:schemaLocation="https://getpsalm.org/schema/config vendor/vimeo/psalm/config.xsd"
>
    <projectFiles>
        <directory name="src" />
    </projectFiles>
    <plugins>
        <pluginClass class="Monadial\Nexus\Psalm\Plugin" />
    </plugins>
</psalm>
```

All five safety rules fire at Psalm level 1. Suppress per-line with `@psalm-suppress NonReadonlyMessage` (and equivalent names), or globally in `psalm.xml` via `<issueHandlers>`.

## See also

- [nexus-core](./core.md) — the actor APIs this plugin analyses
- [Core concepts / actors](../core-concepts/actors.md)
