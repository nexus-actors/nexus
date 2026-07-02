---
sidebar_position: 1
title: nexus-core
related:
  - packages/runtime
  - core-concepts/actors
  - core-concepts/behaviors
  - core-concepts/supervision
---

# nexus-core

Foundational actor model abstractions: `ActorSystem`, `Behavior`, `Props`, `ActorRef`, supervision, lifecycle signals, and the full exception hierarchy.

## What's in this package

**Actor namespace** (`Monadial\Nexus\Core\Actor\`)

- `ActorSystem` — entry point; spawns top-level actors and drives the runtime
- `ActorRef<T>` — type-safe reference; `tell()`, `ask()`, `path()`, `isAlive()`
- `ActorContext<T>` — handler context; spawn, stop, watch, schedule, stash, log
- `Behavior<T>` — immutable behavior definition; `receive`, `withState`, `setup`, `same`, `stopped`, `unhandled`
- `BehaviorWithState<T,S>` — result type for stateful handlers; `next`, `same`, `stopped`, `withBehavior`
- `Props<T>` — spawn configuration; `fromBehavior`, `fromFactory`, `fromContainer`, `fromStatefulFactory`
- `ActorHandler<T>`, `StatefulActorHandler<T,S>`, `AbstractActor<T>` — class-based actor interfaces
- `ActorCell<T>`, `LocalActorRef<T>`, `DeadLetterRef` — internal engine and ref implementations
- `ActorPath`, `ActorState` — path and state-machine types

**Supervision namespace** (`Monadial\Nexus\Core\Supervision\`)

- `SupervisionStrategy` — `oneForOne`, `allForOne`, `exponentialBackoff`
- `Directive` enum — `Restart`, `Stop`, `Resume`, `Escalate`

**Lifecycle namespace** (`Monadial\Nexus\Core\Lifecycle\`)

- `Signal`, `PreStart`, `PostStop`, `PreRestart`, `PostRestart`, `ChildFailed`, `Terminated`

**Message namespace** (`Monadial\Nexus\Core\Message\`)

- `PoisonPill`, `Kill`, `Suspend`, `Resume`, `Watch`, `Unwatch`, `DeadLetter`

**Exception namespace** (`Monadial\Nexus\Core\Exception\`)

- `NexusException`, `ActorInitializationException`, `AskTimeoutException`, `MaxRetriesExceededException`, `InvalidActorPathException`, `InvalidActorStateTransition`

## Install

```bash
composer require nexus-actors/core
```

## Quick example

<!-- verify:skip: requires a running actor system -->
```php title="src/Actor/GreeterActor.php" verify:skip
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;

$runtime = new FiberRuntime();
$system  = ActorSystem::create('app', $runtime);

$behavior = Behavior::receive(static fn ($ctx, $msg) => Behavior::same());
$ref = $system->spawn(Props::fromBehavior($behavior), 'greeter');

$ref->tell(new Greet('world'));
$system->run();
```

## See also

- [Core concepts / actors](../core-concepts/actors.md)
- [Core concepts / behaviors](../core-concepts/behaviors.md)
- [Core concepts / supervision](../core-concepts/supervision.md)
- [nexus-runtime](./runtime.md) — `Duration`, mailbox contracts, and the `Runtime` interface
