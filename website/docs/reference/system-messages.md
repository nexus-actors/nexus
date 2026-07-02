---
title: System messages
related:
  - core-concepts/lifecycle
  - reference/lifecycle-signals
  - core-concepts/supervision
  - reference/config
---

# System messages

System messages are internal control frames that the actor runtime routes ahead of all user messages. They implement the `SystemMessage` marker interface and are never sent by user code directly — with the single exception of `PoisonPill`.

The system message queue has priority over the user mailbox. A `Suspend` or `Kill` sent by the supervision system will be processed before any pending user message.

## PoisonPill

`Monadial\Nexus\Core\Message\PoisonPill`

**When fired:** User code calls `$ref->tell(new PoisonPill())`. This is the only system message intended for direct user-land use.

**Who handles it:** `ActorCell`. The cell enqueues `PoisonPill` in the regular user mailbox (not the system queue), preserving FIFO order with respect to preceding user messages.

**Observable effects:**
- All user messages enqueued before `PoisonPill` are processed first.
- Once reached, the actor stops accepting new messages, sends `PostStop` to itself, waits for all children to stop, then terminates.
- Watchers receive a `Terminated` signal.
- Messages arriving after `PoisonPill` go to dead letters.

```php title="src/Actor/OrderActor.php"
// Gracefully shut down an actor after all pending work drains
$orderActor->tell(new PoisonPill());
```

:::note PoisonPill is FIFO
Because `PoisonPill` travels through the user mailbox, messages sent before it are delivered first. Use `Kill` (internal) for immediate preemptive termination — but `Kill` is not exposed to user code.
:::

---

## Watch and Unwatch

`Monadial\Nexus\Core\Message\Watch` / `Monadial\Nexus\Core\Message\Unwatch`

**When fired:** `$ctx->watch($ref)` and `$ctx->unwatch($ref)` in user actor code. Both are internal; the call routes through `ActorCell` without exposing the message object.

**Who handles it:** The target actor's `ActorCell`. On receiving `Watch`, the cell adds the watcher to its death-watch set. On `Unwatch`, it removes it.

**Observable effects:**
- After a successful `watch()`, when the target terminates (for any reason), the watcher actor receives a `Terminated` signal carrying the target's ref.
- If `watch()` is called on an actor that is already stopped, `Terminated` is delivered immediately to the watcher — no wait.
- `unwatch()` removes the subscription; if the target terminates after `unwatch()`, no `Terminated` is delivered.

```php title="src/Actor/Supervisor.php"
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Lifecycle\Terminated;

$behavior = Behavior::setup(static function (ActorContext $ctx): Behavior {
    $child = $ctx->spawn($childProps, 'worker');
    $ctx->watch($child);

    return Behavior::receive(static function (ActorContext $ctx, object $msg): Behavior {
        return Behavior::same();
    })->onSignal(static function (ActorContext $ctx, object $signal): Behavior {
        if ($signal instanceof Terminated) {
            $ctx->log()->info('child gone: ' . $signal->ref->path());
        }
        return Behavior::same();
    });
});
```

---

## Suspend and Resume

`Monadial\Nexus\Core\Message\Suspend` / `Monadial\Nexus\Core\Message\Resume`

**When fired:** The supervision system sends these automatically — `Suspend` when a child throws and the parent's strategy is being evaluated; `Resume` when the decider returns `Directive::Resume`.

**Who handles it:** `ActorCell`. These are `@internal` and must not be sent by user code.

**Observable effects:**
- `Suspend` halts user-message processing on the target actor. System messages continue to be processed.
- `Resume` re-enables user-message processing and drains the mailbox from where it paused.
- The actor's behavior state is preserved through a suspend/resume cycle; no re-initialization occurs.

:::caution Internal use only
`Suspend` and `Resume` are marked `@internal`. Sending them directly from user code corrupts the actor state machine and may cause `InvalidActorStateTransition`.
:::

---

## DeadLetter

`Monadial\Nexus\Core\Message\DeadLetter`

**When fired:** The runtime creates a `DeadLetter` automatically whenever a message cannot be delivered to its intended recipient. Common causes: the target actor has stopped, the mailbox is closed, or a reply to an `ask` that has already timed out arrives.

**Who handles it:** The system-wide dead-letter sink (`DeadLetterRef`). By default this logs the undeliverable message. Register a PSR-14 listener on `DeadLetter` events to capture them.

**Payload:**

| Property | Type | Description |
|---|---|---|
| `$message` | `object` | The original undelivered message. |
| `$sender` | `ActorRef` | The actor (or system) that sent the message. |
| `$recipient` | `ActorRef` | The actor that could not receive it. |

**Observable effects:**
- The sender receives no error or acknowledgement — message delivery in Nexus is fire-and-forget.
- Dead letters do not trigger supervision.
- A spike in dead letters is a signal of actor lifecycle mismatches; inspect `$recipient->path()` in your listener.

---

## Kill

`Monadial\Nexus\Core\Message\Kill`

**When fired:** The supervision system sends `Kill` when the `MaxRetriesExceededException` threshold is reached and the decider returns `Directive::Stop`, or when `$ctx->stop($childRef)` is called.

**Who handles it:** `ActorCell`. This is `@internal` and bypasses the user mailbox.

**Observable effects:**
- The target actor stops immediately without processing any remaining user messages.
- `PostStop` is still emitted; the actor's cleanup logic runs.
- All unprocessed user messages go to dead letters.
- Watchers receive `Terminated`.

:::caution Kill vs PoisonPill
`Kill` is not exposed to user code. If you need to stop an actor from outside, use `$ref->tell(new PoisonPill())` to drain the mailbox first. `Kill` provides no FIFO guarantee.
:::

---

## See also

- [Lifecycle signals](./lifecycle-signals.md) — `PreStart`, `PostStop`, `Terminated`, `ChildFailed`, `ReceiveTimeout`
- [Supervision](../core-concepts/supervision.md) — how `Suspend`, `Resume`, and `Kill` participate in the supervision cycle
- [Dead letters](../core-concepts/dead-letters.md) — what happens to messages that reach `DeadLetterRef`
- [Gotchas](./gotchas.md#poisonpill-is-fifo-not-preemptive) — PoisonPill ordering guarantee
