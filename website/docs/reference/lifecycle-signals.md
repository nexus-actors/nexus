---
title: Lifecycle signals
related:
  - core-concepts/lifecycle
  - reference/system-messages
  - core-concepts/supervision
  - reference/gotchas
---

# Lifecycle signals

Lifecycle signals are out-of-band notifications the runtime delivers to a behavior via its `onSignal` handler. They implement the `Signal` marker interface. User code never constructs or sends signals — the runtime generates them automatically at well-defined points in the actor lifecycle.

Attach a signal handler with `$behavior->onSignal(fn(ActorContext $ctx, Signal $signal): Behavior)`. A behavior without an `onSignal` attachment receives signals and discards them silently.

```php title="src/Actor/ResourceActor.php"
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Lifecycle\PostStop;
use Monadial\Nexus\Core\Lifecycle\PreStart;
use Monadial\Nexus\Core\Lifecycle\Signal;

$behavior = Behavior::receive(static fn(ActorContext $ctx, object $msg) => Behavior::same())
    ->onSignal(static function (ActorContext $ctx, Signal $signal): Behavior {
        if ($signal instanceof PreStart) {
            // one-time initialization
        }
        if ($signal instanceof PostStop) {
            // release resources
        }
        return Behavior::same();
    });
```

---

## PreStart

`Monadial\Nexus\Core\Lifecycle\PreStart`

**When fired:** Once, immediately after the actor is started by the runtime, before any user message is delivered. On restart (after a crash + `Directive::Restart`), `PreStart` is NOT re-emitted — `PostRestart` is emitted instead.

**Payload:** None. `PreStart` is an empty signal; no constructor parameters.

**Common patterns:**
- Initialize resources that require an active actor context (`$ctx->spawn()`, `$ctx->watch()`).
- Start timers via `$ctx->scheduleRepeatedly()`.
- Log actor start with `$ctx->log()`.

```php title="src/Actor/CacheActor.php"
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Lifecycle\PreStart;
use Monadial\Nexus\Core\Lifecycle\Signal;
use Monadial\Nexus\Runtime\Duration;

Behavior::receive(static fn(ActorContext $ctx, object $msg) => Behavior::same())
    ->onSignal(static function (ActorContext $ctx, Signal $signal): Behavior {
        if ($signal instanceof PreStart) {
            $ctx->scheduleRepeatedly(Duration::seconds(0), Duration::minutes(1), new Refresh());
            $ctx->log()->info('cache actor started');
        }
        return Behavior::same();
    });
```

:::note $ctx->self() is only valid after PreStart
Calling `$ctx->self()` before `PreStart` fires (e.g., during behavior construction) returns an uninitialized ref. See [Gotchas — $ctx->self() validity](./gotchas.md#ctx-self-is-only-valid-after-prestart).
:::

---

## PostStop

`Monadial\Nexus\Core\Lifecycle\PostStop`

**When fired:** Once, after the actor has stopped processing all messages and all its children have stopped. Fired regardless of the reason for stopping: `PoisonPill`, `Kill`, supervision `Stop` directive, or `$ctx->stop(self)`.

**Payload:** None.

**Common patterns:**
- Release open resources (database connections, file handles).
- Flush in-memory state to a store.
- Log actor shutdown with final metrics.

```php title="src/Actor/ConnectionActor.php"
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Lifecycle\PostStop;
use Monadial\Nexus\Core\Lifecycle\Signal;

Behavior::receive(static fn(ActorContext $ctx, object $msg) => Behavior::same())
    ->onSignal(static function (ActorContext $ctx, Signal $signal) use (&$conn): Behavior {
        if ($signal instanceof PostStop) {
            $conn?->close();
            $ctx->log()->info('connection actor stopped');
        }
        return Behavior::same();
    });
```

:::note PostStop fires even after initialization failure
If `Behavior::setup()` throws during startup, `PostStop` is still emitted. Register cleanup in `onSignal` rather than relying on the absence of `PostStop`. See [Gotchas](./gotchas.md#init-failure-still-emits-poststop).
:::

---

## Terminated

`Monadial\Nexus\Core\Lifecycle\Terminated`

**When fired:** Delivered to every actor that registered a death watch (`$ctx->watch($ref)`) when the watched actor stops.

**Payload:**

| Property | Type | Description |
|---|---|---|
| `$ref` | `ActorRef<object>` | The actor reference that has terminated. |

**Common patterns:**
- Scatter-gather: count `Terminated` signals to know when all spawned workers have finished.
- Restart a critical child that died unexpectedly.
- Clean up local maps keyed by actor ref.

```php title="src/Actor/AggregatorActor.php"
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Lifecycle\Signal;
use Monadial\Nexus\Core\Lifecycle\Terminated;

Behavior::receive(static fn(ActorContext $ctx, object $msg) => Behavior::same())
    ->onSignal(static function (ActorContext $ctx, Signal $signal): Behavior {
        if ($signal instanceof Terminated) {
            $ctx->log()->info('watched actor stopped: ' . $signal->ref->path());
        }
        return Behavior::same();
    });
```

:::caution Watching an already-stopped actor delivers Terminated immediately
If you call `$ctx->watch($ref)` on an actor that has already stopped, `Terminated` is delivered to the watcher's mailbox immediately — not when the watch is registered. Handle this in your signal handler.
:::

---

## ChildFailed

`Monadial\Nexus\Core\Lifecycle\ChildFailed`

**When fired:** Delivered to the parent actor when a direct child throws an unhandled exception that the supervision strategy does not automatically handle (i.e., the decider is not overriding the signal path) OR when the parent's `onSignal` handler is registered to intercept it.

**Payload:**

| Property | Type | Description |
|---|---|---|
| `$child` | `ActorRef<object>` | The child actor that failed. |
| `$cause` | `Throwable` | The exception the child threw. |

**Common patterns:**
- Custom failure logging: inspect `$cause` to distinguish transient errors from bugs.
- Escalation: return `Behavior::stopped()` from the signal handler to stop the parent too.
- Alerting: push failure metrics via a separate monitoring actor.

```php title="src/Actor/ParentActor.php"
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Lifecycle\ChildFailed;
use Monadial\Nexus\Core\Lifecycle\Signal;

Behavior::receive(static fn(ActorContext $ctx, object $msg) => Behavior::same())
    ->onSignal(static function (ActorContext $ctx, Signal $signal): Behavior {
        if ($signal instanceof ChildFailed) {
            $ctx->log()->error(
                'child failed',
                ['child' => $signal->child->path(), 'cause' => $signal->cause->getMessage()],
            );
        }
        return Behavior::same();
    });
```

---

## ReceiveTimeout

`Monadial\Nexus\Core\Lifecycle\ReceiveTimeout`

**When fired:** Delivered to an actor when no user message has been received within the duration set by `$ctx->setReceiveTimeout(Duration)`. The timer resets on every user message. System messages do not reset it.

**Payload:**

| Property | Type | Description |
|---|---|---|
| `$configured` | `Duration` | The idle timeout that triggered this signal — the same value passed to `setReceiveTimeout()`. |

**Common patterns:**
- Passivation: stop idle actors to reclaim memory — return `Behavior::stopped()` in the handler.
- Idle ping: send a keepalive message to a downstream system before the connection drops.
- Timeout cascade: escalate if a request-processing actor sits idle for too long.

```php title="src/Actor/SessionActor.php"
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Lifecycle\ReceiveTimeout;
use Monadial\Nexus\Core\Lifecycle\Signal;
use Monadial\Nexus\Runtime\Duration;

$behavior = Behavior::setup(static function (ActorContext $ctx): Behavior {
    $ctx->setReceiveTimeout(Duration::minutes(10));

    return Behavior::receive(static fn(ActorContext $ctx, object $msg) => Behavior::same())
        ->onSignal(static function (ActorContext $ctx, Signal $signal): Behavior {
            if ($signal instanceof ReceiveTimeout) {
                $ctx->log()->info('session idle — passivating');
                return Behavior::stopped();
            }
            return Behavior::same();
        });
});
```

:::note ReceiveTimeout resets on user messages only
`ReceiveTimeout` does not reset when system messages or signals arrive — only when a user message is processed. This is the correct semantics for idle detection. See [Gotchas](./gotchas.md#receivetimeout-resets-on-user-messages-only).
:::

---

## PostRestart and PreRestart

`Monadial\Nexus\Core\Lifecycle\PostRestart` / `Monadial\Nexus\Core\Lifecycle\PreRestart`

Both signals carry `public Throwable $cause` — the exception that triggered the restart.

`PreRestart` fires on the old actor instance (pre-crash) before it is discarded. `PostRestart` fires on the new instance after construction and before `PreStart` would otherwise fire. In practice, attach cleanup logic to `PostStop` rather than `PreRestart` — `PostStop` fires after every stop, restart included.

---

## See also

- [Lifecycle](../core-concepts/lifecycle.md) — full actor state machine with transition diagram
- [System messages](./system-messages.md) — `PoisonPill`, `Kill`, `Watch`, `Suspend`/`Resume`
- [Supervision](../core-concepts/supervision.md) — how `ChildFailed` interacts with strategies and directives
- [Gotchas](./gotchas.md) — edge cases around signal ordering and `$ctx->self()` validity
