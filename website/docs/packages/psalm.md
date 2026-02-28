---
sidebar_position: 5
title: nexus-psalm
---

# nexus-psalm

Psalm plugin for static analysis of Nexus actor code. Provides custom rules
that enforce actor-model safety and type providers that improve generic type
inference.

**Composer:** `nexus-actors/psalm`

**Namespace:** `Monadial\Nexus\Psalm\`

## Setup

Register the plugin in `psalm.xml`:

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

## Actor-model safety rules

The plugin enforces five rules that catch common actor-model violations at
analysis time, before they become runtime bugs.

### NonReadonlyMessage

Actor messages must be immutable. This rule flags any non-`readonly` class
passed to `ActorRef::tell()`, `ActorContext::scheduleOnce()`, or
`ActorContext::scheduleRepeatedly()`.

```php
// Good — readonly class is immutable
final readonly class Greet {
    public function __construct(public string $name) {}
}

$ref->tell(new Greet('world')); // OK

// Bad — mutable class can be changed after send
final class MutableGreet {
    public function __construct(public string $name) {}
}

$ref->tell(new MutableGreet('world')); // ERROR: NonReadonlyMessage
```

**Why:** When an actor sends a message, the sender should not be able to
mutate it afterward. Mutable messages break actor isolation and cause data
races in concurrent systems.

Suppress with `@psalm-suppress NonReadonlyMessage` if needed.

### MutableActorState

Actor handlers should not expose mutable state through public properties.
This rule flags any public non-`readonly` non-`static` property on classes
implementing `ActorHandler` or `StatefulActorHandler`.

```php
// Good — readonly class, no mutable state
final readonly class MyHandler implements ActorHandler {
    public function __construct(private string $name) {}
    // ...
}

// Bad — public mutable property
final class BadHandler implements ActorHandler {
    public int $count = 0; // ERROR: MutableActorState
    // ...
}
```

**Why:** Public mutable state on actor handlers can be modified from outside
the actor, breaking encapsulation. Use `readonly` classes or reduce property
visibility.

Suppress with `@psalm-suppress MutableActorState` if needed.

### NonSerializableClusterMessage

Messages sent through `RemoteActorRef::tell()` must be registered in the
`TypeRegistry` for cross-worker serialization. This rule flags message classes
that lack a `#[MessageType]` attribute.

```php
use Monadial\Nexus\Serialization\MessageType;

// Good — registered for serialization
#[MessageType('order.created')]
final readonly class OrderCreated {
    public function __construct(public string $orderId) {}
}

// Bad — will fail at runtime when sent across workers
final readonly class UnregisteredEvent {
    public function __construct(public string $data) {}
}

$remoteRef->tell(new UnregisteredEvent('x')); // ERROR: NonSerializableClusterMessage
```

**Why:** In a clustered deployment, messages crossing worker boundaries are
serialized. Without a stable type identifier from `#[MessageType]`, the
serializer cannot reconstruct the message on the receiving worker.

This rule only applies to `RemoteActorRef::tell()` — local actor references
are not checked since messages stay in-process.

Suppress with `@psalm-suppress NonSerializableClusterMessage` if needed.

### BlockingCallInHandler

Actor handlers must not call blocking functions. This rule flags calls to
`sleep()`, `usleep()`, `file_get_contents()`, `curl_exec()`, and other
blocking I/O functions inside classes implementing `ActorHandler` or
`StatefulActorHandler`.

```php
final readonly class SlowHandler implements ActorHandler {
    public function handle(ActorContext $ctx, object $message): Behavior
    {
        sleep(1); // ERROR: BlockingCallInHandler
        file_get_contents('https://example.com'); // ERROR: BlockingCallInHandler

        return Behavior::same();
    }
}
```

**Detected functions:** `sleep`, `usleep`, `time_nanosleep`,
`time_sleep_until`, `file_get_contents`, `file_put_contents`, `fread`,
`fwrite`, `fgets`, `fopen`, `curl_exec`, `proc_open`, `shell_exec`, `exec`,
`system`, `passthru`, `popen`.

**Why:** Blocking calls inside actor handlers starve the runtime. A sleeping
actor prevents other actors from processing messages. Use
`ActorContext::scheduleOnce()` for delays and async I/O for external calls.

Non-actor classes are not checked — blocking is only flagged inside actor
handler implementations.

Suppress with `@psalm-suppress BlockingCallInHandler` if needed.

### MutableClosureCapture

Closures passed to `Props::fromFactory()` or `Props::fromStatefulFactory()`
must not capture variables by reference. This rule flags `use (&$var)`
captures in factory closures.

```php
// Good — value capture
$name = 'worker';
Props::fromFactory(static function () use ($name): ActorHandler {
    return new MyHandler($name);
});

// Good — arrow functions capture by value implicitly
Props::fromFactory(static fn () => new MyHandler($name));

// Bad — by-reference capture creates shared state
$counter = 0;
Props::fromFactory(static function () use (&$counter): ActorHandler { // ERROR: MutableClosureCapture
    return new MyHandler($counter++);
});
```

**Why:** Actor factory closures are invoked once per spawn. A by-reference
capture means every actor instance shares the same variable, creating hidden
mutable shared state across actors.

Suppress with `@psalm-suppress MutableClosureCapture` if needed.

## Type providers

### PropsReturnTypeProvider

Improves Psalm's type inference for `Props` factory methods. Without this
provider, template parameters are often erased through closure boundaries,
causing Psalm to infer `Props<object>` instead of the specific message type.

**Supported methods:**

- `Props::fromContainer($container, MyHandler::class)` — extracts `T` from
  `class-string<ActorHandler<T>>` and returns `Props<T>`.
- `Props::fromFactory(fn() => new MyHandler())` — inspects the closure's
  return type, looks up the handler's template parameters, and returns
  `Props<T>`.
- `Props::fromStatefulFactory(fn() => new MyStatefulHandler())` — same as
  above for `StatefulActorHandler<T, S>`, returns `Props<T>`.

This means downstream code gets proper type inference:

```php
// Without plugin: Props<object>
// With plugin: Props<MyCommand>
$props = Props::fromContainer($container, MyHandler::class);

// spawn() returns ActorRef<MyCommand>
$ref = $system->spawn($props, 'my-actor');

// tell() only accepts MyCommand
$ref->tell(new MyCommand('hello')); // OK
$ref->tell(new WrongType());        // Psalm error: type mismatch
```

## Generic type safety

Nexus uses `@template T of object` generics throughout its public API. Key
generic types include:

- `ActorRef<T>` — Ensures `tell()` only accepts messages of type `T`.
- `ActorContext<T>` — Scopes `self()`, `scheduleOnce()`, and `scheduleRepeatedly()` to the actor's message type.
- `Behavior<T>` — Links message handler closures to the actor's protocol type.
- `BehaviorWithState<T, S>` — Adds a state type parameter for stateful actors.
- `Props<T>` — Carries the message type through to `ActorSystem::spawn()`.

At Psalm Level 1, these generics provide compile-time verification that:

- Actors only receive messages matching their declared protocol.
- Behavior handlers return correctly typed `Behavior<T>` values.
- `Props` created via `fromBehavior()`, `fromFactory()`, or `fromContainer()` preserve the message type.
- `ActorContext::spawn()` returns an `ActorRef<C>` matching the child's `Props<C>`.

Running `vendor/bin/psalm` with the Nexus plugin catches type mismatches in
actor message handling before runtime.

## Suppressing issues

All custom issues can be suppressed per-line with `@psalm-suppress`:

```php
/** @psalm-suppress NonReadonlyMessage legitimate use of mutable message */
$ref->tell(new LegacyMessage());
```

Or globally in `psalm.xml`:

```xml
<issueHandlers>
    <PluginIssue name="NonReadonlyMessage">
        <errorLevel type="suppress">
            <directory name="src/Legacy" />
        </errorLevel>
    </PluginIssue>
</issueHandlers>
```
