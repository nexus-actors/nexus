# Actor Lifecycle

## Overview

Every actor in Nexus has a well-defined lifecycle: from creation through active message processing to shutdown. Understanding the lifecycle is essential for correct initialization, resource management, and supervision.

The lifecycle is managed internally by `ActorCell` — the engine behind every actor. `ActorCell` maintains a state machine and dispatches lifecycle signals to the actor's behavior at each transition. Application code interacts with the lifecycle through two complementary mechanisms:

- **`AbstractActor` hooks** — `onPreStart()` and `onPostStop()` methods on the actor class, called at the appropriate moments by the runtime.
- **Signal handlers** — closures attached via `Behavior::receive()->onSignal(...)` or `Behavior::setup()->onSignal(...)`, receiving typed signal objects (`PreStart`, `PostStop`, `Terminated`, `ChildFailed`, `PreRestart`, `PostRestart`).

Both mechanisms are available simultaneously. For most actors, the `AbstractActor` hooks are sufficient. Signal handlers are necessary when the same behavior object must react to lifecycle events in closure-based actors, or when fine-grained control over restart signals is needed.

---

## State Diagram

```
                    spawn()
                      │
                      ▼
                  ┌───────┐
                  │  New  │
                  └───┬───┘
                      │  start() called — resolveWrappers()
                      │  initialState set (if withState)
                      ▼
                ┌──────────┐
                │ Starting │
                └────┬─────┘
                     │  PreStart signal delivered
                     ▼
                ┌─────────┐◄──────────────────────────┐
                │ Running │                           │
                └────┬────┘                           │
          ┌──────────┴───────────┐                    │
          │  Suspend message     │  PoisonPill /       │
          ▼                      │  stop() called      │  Resume message
    ┌───────────┐                │                     │  (from supervision)
    │ Suspended │────────────────┘                     │
    └─────┬─────┘                                      │
          │  Resume message ─────────────────────────►─┘
          │
          │  Stopping initiated while suspended
          ▼
      ┌──────────┐
      │ Stopping │  children sent PoisonPill
      └────┬─────┘  PostStop signal delivered
           │        mailbox closed
           ▼
      ┌─────────┐
      │ Stopped │
      └─────────┘

Restart cycle (within Running):
   handler throws
        │
        ▼
   PreRestart signal delivered
   actor re-initialized (new instance from Props)
   PostRestart signal delivered
        │
        ▼
   back to Running
```

### State reference

| State | Description |
|---|---|
| `New` | Actor has been constructed but `start()` has not yet been called. |
| `Starting` | Behavior wrappers are being resolved (`setup`, `withState`, `withTimers`). |
| `Running` | Actor is processing messages normally. |
| `Suspended` | Message processing is paused (supervision restart pending). The mailbox is not drained. |
| `Stopping` | Children are being stopped and `PostStop` is being delivered. The mailbox will be closed. |
| `Stopped` | Terminal state. The mailbox is closed; no further messages are processed. |

### Valid state transitions

```
New        → Starting
Starting   → Running
Running    → Suspended | Stopping
Suspended  → Running | Stopping
Stopping   → Stopped
Stopped    → (none)
```

Any other transition throws `InvalidActorStateTransition`.

---

## Lifecycle Hooks on AbstractActor

`AbstractActor` is the recommended base class for actors that need lifecycle hooks. It implements `ActorHandler` and provides two overridable no-op methods:

```php
use Monadial\Nexus\Core\Actor\AbstractActor;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;

final class OrderActor extends AbstractActor
{
    private mixed $resource = null;

    public function onPreStart(ActorContext $ctx): void
    {
        // Acquire resources, spawn children, schedule timers.
        $this->resource = $this->openConnection();
        $ctx->scheduleRepeatedly(Duration::zero(), Duration::seconds(30), new HeartbeatTick());
    }

    public function handle(ActorContext $ctx, object $message): Behavior
    {
        return Behavior::same();
    }

    public function onPostStop(ActorContext $ctx): void
    {
        // Release resources. All children have already stopped.
        $this->resource?->close();
    }
}
```

### `onPreStart(ActorContext $ctx)`

Called once, after the actor transitions to `Running` and immediately before the first user message is dequeued. Children are not yet running. The actor's `$ctx->self()` reference is available.

This is the correct place to:
- Acquire connections, open files, or establish other I/O resources.
- Spawn child actors via `$ctx->spawn()`.
- Schedule recurring timers via `$ctx->scheduleRepeatedly()`.
- Subscribe to external event sources.

### `handle(ActorContext $ctx, object $message): Behavior`

The message handler. Called for every user message dequeued from the mailbox, in the order received. Must return one of:

| Return value | Effect |
|---|---|
| `Behavior::same()` | Keep processing with the same behavior. |
| `Behavior::stopped()` | Initiate graceful shutdown. |
| `Behavior::unhandled()` | Route the message to dead letters. |
| A new `Behavior` instance | Switch behavior (advanced use). |

### `onPostStop(ActorContext $ctx)`

Called once during `Stopping`, after all child actors have received `PoisonPill` and after the actor's own `PostStop` signal has been dispatched. All scheduled timers are cancelled before this call.

The actor is still in the `Stopping` state during this call. `$ctx->self()` is available but the mailbox is no longer accepting messages.

> **Note:** `onPostStop()` executes synchronously in the actor's fiber/coroutine. Blocking I/O inside `onPostStop()` blocks the entire shutdown sequence. For heavy cleanup, prefer non-blocking alternatives or enqueue the work onto a separate task.

---

## Signal Handling

Signals are typed objects implementing the `Signal` interface, delivered by the runtime outside the normal message queue. They can be intercepted by attaching a handler to a behavior via `->onSignal(...)`.

```php
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Lifecycle\PreStart;
use Monadial\Nexus\Core\Lifecycle\PostStop;
use Monadial\Nexus\Core\Lifecycle\Signal;

$behavior = Behavior::receive(static fn(ActorContext $ctx, object $msg) => Behavior::same())
    ->onSignal(static function (ActorContext $ctx, Signal $signal): Behavior {
        return match (true) {
            $signal instanceof PreStart => /* initialize */ Behavior::same(),
            $signal instanceof PostStop => /* cleanup   */ Behavior::same(),
            default                     => Behavior::same(),
        };
    });
```

The signal handler receives the same `ActorContext` as the message handler and must return a `Behavior`. Returning `Behavior::same()` keeps the current behavior unchanged.

> **Tip:** Signal handlers are the only way to react to lifecycle events in closure-based actors (those created with `Behavior::receive()` or `Behavior::setup()`). Class-based actors extending `AbstractActor` should use `onPreStart()`/`onPostStop()` instead — they are simpler and require no return value.

---

## PreStart Signal vs onPreStart()

Both `PreStart` (the signal) and `onPreStart()` (the hook) fire at the same moment: after the actor transitions to `Running`, before the first user message.

The difference is mechanical:

| | `PreStart` signal | `onPreStart()` hook |
|---|---|---|
| **Available on** | Any behavior via `->onSignal(...)` | Classes extending `AbstractActor` |
| **Return value** | Must return `Behavior` | `void` |
| **Use case** | Closure-based actors, behavior composition | Class-based actors |

`ActorCell.start()` delivers `PreStart` after `transitionTo(Running)`. If a class extends `AbstractActor`, the `PreStart` signal handler inside the runtime calls `onPreStart()` and returns `Behavior::same()` automatically.

There is no ordering difference. Prefer `onPreStart()` for class-based actors — it is clearer and does not require a `match` expression on the signal type.

---

## PostStop Signal

`PostStop` is delivered during `initiateStop()`, which is triggered by:
- A `PoisonPill` message arriving in the mailbox.
- `$ctx->stop($child)` being called from the parent.
- The actor returning `Behavior::stopped()` from its message handler.
- The actor system shutting down.

**Delivery sequence during stop:**

1. All spawned tasks are cancelled.
2. All keyed timers are cancelled.
3. All children receive `PoisonPill` (non-blocking — the messages are enqueued, not awaited).
4. `PostStop` signal is delivered to this actor's behavior.
5. The mailbox is closed.
6. State transitions to `Stopped`.

> **Caution:** Children are sent `PoisonPill` before `PostStop` is delivered to the parent. Child actors stop asynchronously — there is no guarantee that children are fully stopped when `PostStop` fires in the parent. If strict ordering is required (e.g., flushing a buffer through a child before stopping), use `ctx->watch($child)` and wait for the `Terminated` signal before initiating the parent's own cleanup.

**What is safe in PostStop:**
- Closing local resources (file handles, in-process connections).
- Logging.
- Sending `tell()` to other actors (the message is enqueued; processing depends on whether the target is still alive).

**What to avoid in PostStop:**
- Long blocking I/O (delays shutdown).
- Calling `$ctx->spawn()` (the actor is stopping).
- Calling `$ctx->ask()` (the future will not resolve).

---

## Terminated Signal

`Terminated` is delivered to an actor when a watched actor stops. Register a watch with `$ctx->watch($ref)` and handle the signal:

```php
use Monadial\Nexus\Core\Lifecycle\Terminated;

final class SupervisorActor extends AbstractActor
{
    private ?ActorRef $worker = null;

    public function onPreStart(ActorContext $ctx): void
    {
        $this->worker = $ctx->spawn(Props::fromFactory(fn() => new WorkerActor()), 'worker');
        $ctx->watch($this->worker);
    }

    public function handle(ActorContext $ctx, object $message): Behavior
    {
        return Behavior::same();
    }
}
```

Attach the signal handler via `->onSignal()`:

```php
$behavior = Behavior::setup(static function (ActorContext $ctx): Behavior {
    $worker = $ctx->spawn(Props::fromFactory(fn() => new WorkerActor()), 'worker');
    $ctx->watch($worker);

    return Behavior::receive(static fn(ActorContext $ctx, object $msg) => Behavior::same())
        ->onSignal(static function (ActorContext $ctx, Signal $signal) use ($worker): Behavior {
            if ($signal instanceof Terminated && $signal->ref->path() === $worker->path()) {
                // Worker has stopped — take corrective action.
                $ctx->log()->warning('Worker stopped unexpectedly');
            }

            return Behavior::same();
        });
});
```

`Terminated` carries the `ActorRef` of the stopped actor in `$signal->ref`. Compare by path if the ref itself is not in scope.

### When to use watch()

- To detect unexpected termination of a dependency actor.
- To coordinate cleanup across sibling actors that are not in a parent-child relationship.
- To implement "reaper" patterns where one actor waits for several others to complete.

> **Note:** The parent of an actor receives `ChildFailed` (not `Terminated`) when a child throws an exception handled by supervision. `Terminated` is only delivered to actors that explicitly registered via `watch()`.

---

## ChildFailed Signal

`ChildFailed` is delivered to the **parent** of a child actor that threw an unhandled exception during message processing. It carries the failing child's `ActorRef` and the `Throwable` that caused the failure.

```php
use Monadial\Nexus\Core\Lifecycle\ChildFailed;

$behavior = Behavior::receive(static fn(ActorContext $ctx, object $msg) => Behavior::same())
    ->onSignal(static function (ActorContext $ctx, Signal $signal): Behavior {
        if ($signal instanceof ChildFailed) {
            $ctx->log()->error('Child failed', [
                'child' => (string) $signal->child->path(),
                'error' => $signal->cause->getMessage(),
            ]);
            // Optionally restart the child manually, stop self, or escalate.
        }

        return Behavior::same();
    });
```

The parent receives `ChildFailed` after the supervision strategy's decider has run. If the decider chose `Directive::Restart`, the child is already restarting by the time the parent sees the signal. If the decider chose `Directive::Stop`, the child is stopped.

> **Note:** `ChildFailed` is a low-level notification. Most applications configure supervision strategies rather than handling this signal directly. See the [supervision documentation](../core/supervision.md) for a full explanation of directives and strategies.

---

## Restart Signals: PreRestart and PostRestart

When supervision restarts an actor, two additional signals bracket the restart:

- **`PreRestart(Throwable $cause)`** — Delivered to the old actor instance just before it is discarded. The cause of the failure is available. Use this to release resources held by the failing instance.
- **`PostRestart(Throwable $cause)`** — Delivered to the new actor instance after it is initialized. The cause is carried for diagnostic purposes.

```php
use Monadial\Nexus\Core\Lifecycle\PreRestart;
use Monadial\Nexus\Core\Lifecycle\PostRestart;

$behavior = Behavior::receive(static fn(ActorContext $ctx, object $msg) => Behavior::same())
    ->onSignal(static function (ActorContext $ctx, Signal $signal): Behavior {
        return match (true) {
            $signal instanceof PreRestart  => /* flush, close, log */ Behavior::same(),
            $signal instanceof PostRestart => /* re-open resources */ Behavior::same(),
            default                        => Behavior::same(),
        };
    });
```

`AbstractActor` does not expose `onPreRestart()` / `onPostRestart()` hooks. Use `->onSignal(...)` when these signals matter.

---

## Stashing Messages

Stashing temporarily defers messages that arrive before the actor is ready to handle them. A common pattern is to stash messages during initialization, then replay them after setup completes.

```php
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Lifecycle\PreStart;
use Monadial\Nexus\Core\Lifecycle\Signal;

$behavior = Behavior::setup(static function (ActorContext $ctx): Behavior {
    // Actor is not yet ready — stash all incoming messages.
    $initializing = Behavior::receive(static function (ActorContext $ctx, object $msg): Behavior {
        $ctx->stash();
        return Behavior::same();
    });

    // Once setup completes, switch to the ready behavior and replay stashed messages.
    $ready = Behavior::receive(static fn(ActorContext $ctx, object $msg) => match (true) {
        $msg instanceof DoWork => /* handle */ Behavior::same(),
        default                => Behavior::unhandled(),
    });

    // Simulate async initialization here (e.g., connection established).
    // For the transition, schedule a message to self:
    $ctx->scheduleOnce(Duration::zero(), new InitComplete());

    return $initializing->onSignal(static function (ActorContext $ctx, Signal $signal) use ($ready): Behavior {
        return Behavior::same();
    });
});
```

A simpler, self-contained stash pattern using `Behavior::withStash()`:

```php
$behavior = Behavior::withStash(100, static function (StashBuffer $stash): Behavior {
    $initializing = Behavior::receive(static function (ActorContext $ctx, object $msg) use ($stash): Behavior {
        if ($msg instanceof Ready) {
            // Unstash all buffered messages, then switch to ready behavior.
            return $stash->unstashAll(Behavior::receive(
                static fn(ActorContext $ctx, object $msg) => Behavior::same(),
            ));
        }

        $stash->stash();
        return Behavior::same();
    });

    return $initializing;
});
```

### Stash guarantees

- Messages are replayed in FIFO order when `unstashAll()` is called.
- The stash buffer is bounded; exceeding capacity throws `StashOverflowException`.
- If the actor stops while messages are stashed, the stashed messages are discarded (not sent to dead letters).

> **Caution:** Do not call `stash()` inside a `PostStop` signal handler. The actor is already stopping; stashed messages will never be replayed.

---

## Complete Class-Based Example

The following example demonstrates all lifecycle hooks on a single actor:

```php
use Monadial\Nexus\Core\Actor\AbstractActor;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Lifecycle\ChildFailed;
use Monadial\Nexus\Core\Lifecycle\Signal;
use Monadial\Nexus\Core\Lifecycle\Terminated;
use Monadial\Nexus\Runtime\Duration;

final class PipelineActor extends AbstractActor
{
    private ?ActorRef $worker = null;
    private mixed $connection = null;

    // Called once, after state is New → Running.
    // Spawn children and acquire resources here.
    public function onPreStart(ActorContext $ctx): void
    {
        $this->connection = $this->openDatabaseConnection();

        $this->worker = $ctx->spawn(
            Props::fromFactory(fn() => new WorkerActor($this->connection)),
            'worker',
        );

        $ctx->watch($this->worker);

        // Schedule a periodic health check.
        $ctx->scheduleRepeatedly(Duration::seconds(10), Duration::seconds(10), new HealthCheck());
    }

    public function handle(ActorContext $ctx, object $message): Behavior
    {
        return match (true) {
            $message instanceof Process    => $this->onProcess($ctx, $message),
            $message instanceof HealthCheck => $this->onHealthCheck($ctx),
            default                        => Behavior::unhandled(),
        };
    }

    // Called once, during Stopping, after children received PoisonPill.
    // Release resources here.
    public function onPostStop(ActorContext $ctx): void
    {
        $this->connection?->close();
        $ctx->log()->info('PipelineActor stopped');
    }

    private function onProcess(ActorContext $ctx, Process $message): Behavior
    {
        $this->worker?->tell(new WorkItem($message->payload));
        return Behavior::same();
    }

    private function onHealthCheck(ActorContext $ctx): Behavior
    {
        if ($this->connection === null || !$this->connection->isAlive()) {
            $ctx->log()->warning('Connection lost; stopping actor');
            return Behavior::stopped();
        }

        return Behavior::same();
    }

    private function openDatabaseConnection(): mixed
    {
        // Application-specific connection logic.
        return null;
    }
}
```

To intercept `Terminated` and `ChildFailed` signals on a class-based actor, use `Behavior::setup()` with `->onSignal()` in `Props::fromBehavior()`, or implement `ActorHandler` directly and override signal handling manually. `AbstractActor` handles only `PreStart` and `PostStop` hooks.

---

## Common Mistakes

### Initializing state in the constructor instead of onPreStart()

```php
// Wrong: acquires connection during DI construction, before actor is running.
final class BadActor extends AbstractActor
{
    private readonly Connection $conn;

    public function __construct()
    {
        $this->conn = new Connection('mysql://...'); // runs at container boot time
    }
}

// Correct: defer to onPreStart().
final class GoodActor extends AbstractActor
{
    private ?Connection $conn = null;

    public function onPreStart(ActorContext $ctx): void
    {
        $this->conn = new Connection('mysql://...');
    }
}
```

When the actor class is resolved from the Symfony DI container, its constructor runs at container resolution time — before the actor system is running, before a worker context exists. Acquiring Swoole-specific resources (connection pools, channels) in the constructor will fail or produce incorrect behavior.

### Doing long-running I/O in onPostStop()

```php
// Wrong: blocks shutdown while flushing a large buffer.
public function onPostStop(ActorContext $ctx): void
{
    foreach ($this->pendingEvents as $event) {
        $this->slowRemoteApi->send($event); // blocking HTTP call per event
    }
}

// Better: flush synchronously only if the operation is fast,
// or use a background task pattern and accept that pending events may be lost.
public function onPostStop(ActorContext $ctx): void
{
    if (count($this->pendingEvents) < 10) {
        $this->fastLocalFlush($this->pendingEvents);
    } else {
        $ctx->log()->warning('Dropping pending events on stop', [
            'count' => count($this->pendingEvents),
        ]);
    }
}
```

### Calling ask() in onPostStop()

```php
// Wrong: the response future will never resolve because the actor is stopping.
public function onPostStop(ActorContext $ctx): void
{
    $future = $this->peer->ask(new Flush(), Duration::seconds(5));
    $future->await(); // hangs until AskTimeoutException
}
```

Use `tell()` for fire-and-forget cleanup notifications instead of `ask()`.

### Forgetting to watch() before expecting Terminated

```php
// Wrong: Terminated will never arrive without watch().
public function onPreStart(ActorContext $ctx): void
{
    $this->child = $ctx->spawn(Props::fromFactory(fn() => new ChildActor()), 'child');
    // $ctx->watch($this->child); — omitted by mistake
}
```

`Terminated` is only delivered to actors that registered a watch. The parent of a child automatically receives `ChildFailed` on exception, but `Terminated` on normal stop requires an explicit `watch()`.
