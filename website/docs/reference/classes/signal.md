---
title: Signal
sidebar_position: 16
related:
  - core-concepts/lifecycle
  - core-concepts/supervision
  - reference/classes/behavior
---

# Signal

The marker interface for actor lifecycle signals delivered by the Nexus runtime.

## What it does

`Signal` is implemented by the four built-in lifecycle notifications that the runtime
generates automatically as an actor moves through its lifecycle. You never send a
`Signal` directly — instead you attach a handler to a behavior with
`Behavior::onSignal()`, and the runtime calls it when a signal arrives.

The four built-in signals are:

| Signal | When it is delivered |
|---|---|
| `PreStart` | Before the first user message is processed |
| `PostStop` | After the actor stops and all children are shut down |
| `Terminated` | When a watched actor terminates (carries the terminated `ActorRef`) |
| `ChildFailed` | When a direct child throws an unhandled exception (carries child ref + throwable) |

Signals are processed out-of-band: they arrive on a separate fast path before
ordinary mailbox messages and are never visible in the main handler closure.

## Example

```php title="src/Actor/ResourceActor.php"
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Lifecycle\ChildFailed;
use Monadial\Nexus\Core\Lifecycle\PostStop;
use Monadial\Nexus\Core\Lifecycle\PreStart;
use Monadial\Nexus\Core\Lifecycle\Signal;
use Monadial\Nexus\Core\Lifecycle\Terminated;

$behavior = Behavior::receive(
    static fn(ActorContext $ctx, object $msg) => Behavior::same(),
)->onSignal(static function (ActorContext $ctx, Signal $signal): Behavior {
    if ($signal instanceof PreStart) {
        $ctx->log()->info('Actor started — opening resources');
        // Initialise DB connections, timers, subscriptions…
    }

    if ($signal instanceof PostStop) {
        $ctx->log()->info('Actor stopped — releasing resources');
        // Close connections, flush buffers…
    }

    if ($signal instanceof Terminated) {
        $ctx->log()->warning('Watched actor terminated', [
            'path' => (string) $signal->ref->path(),
        ]);
    }

    if ($signal instanceof ChildFailed) {
        $ctx->log()->error('Child failed', [
            'child' => (string) $signal->child->path(),
            'error' => $signal->cause->getMessage(),
        ]);
    }

    return Behavior::same();
});
```

## Key members

- `PreStart` — emitted once at startup; ideal for deferred initialisation (spawning
  children, opening connections).
- `PostStop` — emitted once at shutdown; guaranteed to run after all children have
  stopped, so it is safe to close shared resources here.
- `Terminated(ActorRef $ref)` — emitted per watched peer. Register a watch with
  `$ctx->watch($ref)`; cancel with `$ctx->unwatch($ref)`.
- `ChildFailed(ActorRef $child, Throwable $cause)` — emitted when a child throws and
  the supervision strategy escalates to the parent.

## Attaching a signal handler

Chain `->onSignal($handler)` onto any `Behavior` value:

```php
$base = Behavior::receive(static fn($ctx, $msg) => Behavior::same());

$withLifecycle = $base->onSignal(
    static fn(ActorContext $ctx, Signal $signal): Behavior
        => Behavior::same()
);
```

The same pattern works on `Behavior::setup()` and `Behavior::withState()` results.
Both the message handler and the signal handler independently return the next
`Behavior`, so you can change behavior in response to a signal.

## Full API reference

[Full interface and built-in signal classes](https://api.nexusactors.com/classes/Monadial-Nexus-Core-Lifecycle-Signal.html)

## See also

- [Lifecycle concept](../../core-concepts/lifecycle) — lifecycle phase diagram
- [Supervision concept](../../core-concepts/supervision) — how ChildFailed feeds the supervision tree
- [Behavior](behavior) — the `onSignal()` method
