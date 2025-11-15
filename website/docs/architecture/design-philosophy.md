---
sidebar_position: 1
title: Design Philosophy
---

# Design Philosophy

Nexus is built on a set of deliberate design decisions that shape every aspect
of the library. This page explains the reasoning behind each.

## Why the actor model for PHP

The actor model is not new. Erlang/OTP has proven its viability for building
fault-tolerant, concurrent systems over decades. Akka brought the same patterns
to the JVM. Nexus brings them to PHP.

PHP's traditional request-response model already resembles actor semantics in
several ways: each request is isolated, processes a message (the HTTP request),
and produces a response. Nexus formalizes this pattern, extending it to
long-running processes, background workers, and event-driven architectures where
PHP is increasingly used.

The actor model gives PHP developers:

- **Isolation** -- Each actor processes messages sequentially, eliminating shared-state concurrency bugs.
- **Hierarchy** -- Parent-child relationships provide a natural structure for organizing concurrent work.
- **Fault tolerance** -- Supervision trees handle failures systematically instead of relying on ad-hoc try/catch blocks.

## Immutability first

All core value objects in Nexus are `readonly` classes:

- `Behavior` is immutable. Swapping behavior means returning a new instance, never mutating the current one.
- `BehaviorWithState` is immutable. State transitions produce new values.
- `Props`, `MailboxConfig`, `Envelope`, `Duration`, `SwooleConfig`, and `SupervisionStrategy` are all `final readonly`.
- `ActorPath` is immutable. Child paths are created by returning new instances.

Immutability eliminates an entire category of bugs related to shared mutable
state, which is critical in a concurrent system where multiple actors may
reference the same configuration or path objects.

Where optional values are needed, Nexus uses `Option` types from fp4php rather
than nullable parameters, making the presence or absence of values explicit in
the type system.

## Type safety via generics

Nexus targets Psalm Level 1 -- the strictest analysis level. The entire public
API is annotated with `@template` generics:

- `ActorRef<T>` ensures that `tell()` only accepts messages of type `T`.
- `Behavior<T>` links handler closures to the actor's message protocol.
- `Props<T>` carries the message type through spawning.
- `ActorContext<T>` scopes the context to the actor's own message type.

This means that sending the wrong message type to an actor is caught at
analysis time, not at runtime. The `nexus-psalm` plugin enables these checks
in consuming projects.

## Runtime pluggability

The `nexus-core` package contains zero references to Fibers or Swoole. All
concurrency is abstracted behind the `Runtime` interface. Actor behaviors,
Props, supervision strategies, and mailbox configurations are completely
portable between runtimes.

This has practical benefits:

- Tests run on the Fiber runtime without requiring Swoole.
- The same actor code deploys to development (Fiber) and production (Swoole) environments without changes.
- Future runtimes (ReactPHP, AMPHP, or custom implementations) can be added without modifying existing actor code.

## Composable configuration

Actor configuration uses a builder pattern with immutable transformations:

```php
$props = Props::fromBehavior($behavior)
    ->withMailbox(MailboxConfig::bounded(100, OverflowStrategy::DropOldest))
    ->withSupervision(SupervisionStrategy::oneForOne(maxRetries: 5));
```

Pipe-friendly functions are provided for use with PHP's pipe operator:

```php
use function Monadial\Nexus\Core\Actor\Functions\withMailbox;
use function Monadial\Nexus\Core\Actor\Functions\withSupervision;
```

Each `with*` method returns a new instance, so configurations can be safely
shared and extended without mutation.

## Supervision over exception handling

Nexus follows the "let it crash" philosophy from Erlang/OTP. Instead of
wrapping every operation in try/catch blocks, actors define supervision
strategies that declare what should happen when a child fails:

- **Restart** -- Recreate the failed actor with fresh state.
- **Stop** -- Permanently stop the failed actor.
- **Resume** -- Ignore the failure and continue processing.
- **Escalate** -- Propagate the failure to the parent's supervisor.

Three strategy types are available:

- `SupervisionStrategy::oneForOne()` -- Only the failed child is acted upon.
- `SupervisionStrategy::allForOne()` -- All children are acted upon when one fails.
- `SupervisionStrategy::exponentialBackoff()` -- Restarts with increasing delays.

This approach separates error handling policy from business logic, making both
easier to reason about and test independently.

## Location transparency

The `ActorRef<T>` interface is the same whether the actor is local (in the same
process), in another worker process, or on a remote machine. Code that sends
messages uses `$ref->tell($message)` without knowing the actor's physical
location.

Currently, Nexus provides `LocalActorRef` for in-process messaging and
`DeadLetterRef` as a null-object endpoint. The cluster design (in progress)
will add `RemoteActorRef` for cross-process and cross-server messaging, using
the same `ActorRef` interface.

## PSR compatibility

Nexus integrates with standard PHP interfaces rather than inventing its own:

- **PSR-11 (Container)** -- `Props::fromContainer()` resolves actor instances from any PSR-11 container.
- **PSR-3 (Logging)** -- `ActorContext::log()` returns a `Psr\Log\LoggerInterface`. The `ActorSystem` accepts an optional logger at creation.
- **PSR-14 (Event Dispatcher)** -- `ActorSystem::create()` accepts an optional `EventDispatcherInterface` for system-level events.
- **PSR-20 (Clock)** -- `ActorSystem::create()` accepts an optional `ClockInterface` for testable time.

This means Nexus works with Monolog, Symfony's event dispatcher, any PSR-11
container (Laravel, Symfony, PHP-DI), and any PSR-20 clock implementation
without additional adapters.
