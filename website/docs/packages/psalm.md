---
sidebar_position: 5
title: nexus-psalm
---

# nexus-psalm

Psalm plugin for static analysis of Nexus actor code.

**Composer:** `monadial/nexus-psalm`

**Namespace:** `Monadial\Nexus\Psalm\`

## Setup

Register the plugin in your `psalm.xml`:

```xml
<plugins>
    <pluginClass class="Monadial\Nexus\Psalm\Plugin" />
</plugins>
```

The plugin class implements `Psalm\Plugin\PluginEntryPointInterface`.

## Recommended configuration

Nexus is developed and tested at Psalm Level 1 (the strictest level). This
level is recommended for projects using Nexus to get the full benefit of
generic type checking on actor message protocols.

```xml
<?xml version="1.0"?>
<psalm
    errorLevel="1"
    resolveFromConfigFile="true"
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

## Generic type safety

Nexus uses `@template T of object` generics throughout its public API. Key
generic types include:

- `ActorRef<T>` -- Ensures `tell()` only accepts messages of type `T`.
- `ActorContext<T>` -- Scopes `self()`, `scheduleOnce()`, and `scheduleRepeatedly()` to the actor's message type.
- `Behavior<T>` -- Links message handler closures to the actor's protocol type.
- `BehaviorWithState<T, S>` -- Adds a state type parameter for stateful actors.
- `Props<T>` -- Carries the message type through to `ActorSystem::spawn()`.

At Psalm Level 1, these generics provide compile-time verification that:

- Actors only receive messages matching their declared protocol.
- Behavior handlers return correctly typed `Behavior<T>` values.
- `Props` created via `fromBehavior()`, `fromFactory()`, or `fromContainer()` preserve the message type.
- `ActorContext::spawn()` returns an `ActorRef<C>` matching the child's `Props<C>`.

Running `vendor/bin/psalm` with the Nexus plugin catches type mismatches in
actor message handling before runtime.
