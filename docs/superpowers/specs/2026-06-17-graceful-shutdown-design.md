# Graceful Shutdown Design

**Status:** Spec — pending review
**Author:** brainstorming session 2026-06-17
**Target branch:** `feat/nexus-doctrine` (or a fresh `feat/graceful-shutdown` cut from it)
**Target packages:** `nexus-core`, `nexus-runtime-swoole`, `nexus-worker-pool-swoole`, `nexus-http-server-swoole`

## Goal

Eliminate the `[FATAL] all coroutines (count: N) are asleep - deadlock!` errors and the `Uncaught Swoole\Error: API must be called in the coroutine` crash that fire on every worker stop. Workers should exit cleanly within the configured shutdown timeout, with no orphaned coroutines and no force-termination warnings (`Worker_reactor_try_to_exit() ERRNO 9101: worker exit timeout`).

Today's wallet-app log (and any Swoole-thread Nexus app) shows this on every `docker compose restart`:

```
[FATAL ERROR]: all coroutines (count: 2) are asleep - deadlock!
  [Coroutine-3] SwooleMailbox::dequeueBlocking() — actor message loop
  [Coroutine-2] ThreadQueueTransport::startReceiveLoop() — sleep loop
Fatal error: Swoole\Error: API must be called in the coroutine
  in SwooleMailbox::enqueue() called from bindWorkerStop hook
```

These are correctness issues. The actor system's message loop blocks indefinitely on a coroutine channel `pop()` with no cancellation mechanism; when Swoole tries to stop the worker, it can't wake the blocked coroutines, so it force-terminates. Meanwhile the HTTP server's `WorkerStop` hook tries to send a `PoisonPill` from outside a coroutine context, which `Swoole\Coroutine\Channel::push()` rejects.

## Non-goals

- **Cluster-wide graceful shutdown** (multi-node coordination). Out of scope.
- **Draining in-flight HTTP requests.** Swoole already does this for inbound HTTP via its native graceful-shutdown timer.
- **Persisting unprocessed mailbox state.** Actor systems are ephemeral by design; if you need durability use the persistence layer.
- **`FiberRuntime` changes.** Fiber-mode mailboxes are already cancellable (the scheduler is single-threaded; closing the SplQueue is observable). No work needed there.

## Architecture

Five pieces, layered:

### 1. Cancellable mailbox

Add a `close()` method to the `Mailbox<T>` interface. After `close()`:
- `enqueue()` returns `EnqueueResult::Dropped` (no exception).
- `dequeue()` / `dequeueBlocking()` returns `Option::none()` once the channel is drained.

**`SwooleMailbox::close()`** calls `Channel::close()` on the underlying coroutine channel. Swoole's documented behavior: all blocked `pop()` callers immediately return `false`. The mailbox's `dequeueBlocking()` translates `false` to `Option::none()`, which the message loop already handles as "no message available" — extending the convention to "no more messages, ever" requires one additional flag check.

**`InMemoryMailbox::close()`** (Fiber path) sets a `closed` bool. Subsequent `dequeue()` calls return none.

### 2. Coroutine-context-safe `enqueue`

`SwooleMailbox::enqueue()` currently calls `Channel::push()` which requires coroutine context. The HTTP server's `WorkerStop` hook fires outside any coroutine, so when `ActorSystem::shutdown()` broadcasts `PoisonPill` via `tell()`, the push crashes.

Two viable fixes:

**Option A (preferred)**: `SwooleMailbox::enqueue()` checks `Coroutine::getCid() === -1`. If outside coroutine, wraps the actual push in a fresh `Coroutine::create()`. Returns `EnqueueResult::Accepted` immediately (the push happens asynchronously inside the coroutine). Caveat: message ordering across coroutine boundaries is no longer guaranteed for messages enqueued during the no-coroutine window, but the only producer that ever runs outside coroutine context is the shutdown sequence itself, and ordering of `PoisonPill` doesn't matter.

**Option B**: Document `enqueue()` as coroutine-only, fix the call sites that violate this. The shutdown hook would wrap itself in `Coroutine::create()` instead. Less invasive at the mailbox layer, but requires every caller to know the rule.

Going with A — making the mailbox safe-by-default eliminates a whole class of foot-guns.

### 3. Transport graceful stop

`ThreadQueueTransport::startReceiveLoop()` runs in a coroutine that polls the cross-thread queue with adaptive sleep (`0µs → 100µs → 1ms → 10ms`). To stop cleanly, add an internal `stopping` flag the loop checks every wakeup:

```php
public function stop(): void
{
    $this->stopping = true;
}

private function startReceiveLoop(): void
{
    Coroutine::create(function (): void {
        while (!$this->stopping) {
            $envelope = $this->queue->pop(0.01);
            if ($envelope !== null) {
                $this->handler($envelope);
                continue;
            }
            Coroutine::sleep($this->backoff());
        }
    });
}
```

Worst-case wake latency = current backoff slot (capped at 10ms). Acceptable for shutdown.

### 4. Orchestrated `ActorSystem::shutdown()`

Replace the current "broadcast PoisonPill then trust the world" flow with a deadline-driven orchestration:

```php
public function shutdown(Duration $timeout): void
{
    $deadline = $this->clock->now() + $timeout->toNanos();
    $this->stopping = true;

    // 1. Send PoisonPill to root children (coroutine-safe via fix #2).
    foreach ($this->children as $child) {
        $child->tell(new PoisonPill());
    }

    // 2. Wait for actors to drain — poll every 10ms.
    while ($this->clock->now() < $deadline && !$this->allStopped()) {
        $this->runtime->yield();
    }

    // 3. Force-close any survivors. Closing the mailbox unblocks the
    //    actor's message loop, which returns Behavior::stopped() naturally.
    foreach ($this->aliveChildren() as $child) {
        $child->mailbox()->close();
    }

    // 4. Tear down transports last (after actors are gone).
    foreach ($this->transports as $transport) {
        $transport->stop();
    }
}
```

`yield()` is the existing `Runtime` method; under Swoole it returns control to the scheduler so other coroutines (the actors processing their PoisonPills) can make progress.

### 5. HTTP server shutdown hook fix

`SwooleServerEventBinder::bindWorkerStop` registers a callback on Swoole's `WorkerStop` event. With Fix #2 the existing call already works, but we go one step further: wrap the whole shutdown sequence in `Coroutine::create()` defensively so any `yield()` calls inside `shutdown()` have a coroutine context to suspend in:

```php
$server->on('WorkerStop', function () use ($system, $timeout): void {
    if (Coroutine::getCid() === -1) {
        Coroutine::create(static fn() => $system->shutdown($timeout));
    } else {
        $system->shutdown($timeout);
    }
});
```

## Testing strategy

### Unit tests

- `SwooleMailboxCloseTest` — `close()` returns Dropped for new enqueues, drains existing queue, blocked dequeue returns none
- `InMemoryMailboxCloseTest` — same surface, Fiber path
- `SwooleMailboxOutOfCoroutineEnqueueTest` — `enqueue()` called from main thread (no coroutine) does not crash
- `ThreadQueueTransportStopTest` — `stop()` exits the receive loop within one backoff cycle
- `ActorSystemShutdownDeadlineTest` — actors that ignore PoisonPill are force-closed when deadline passes

### Integration tests

- `tests/Integration/Fiber/CleanShutdownTest.php` — spawn 100 actors, call `shutdown(Duration::seconds(1))`, assert all stopped within deadline
- `tests/Integration/Swoole/CleanShutdownUnderLoadTest.php` — spawn 100 actors under steady message load, call `shutdown(Duration::seconds(2))`, verify no fatal-error stack traces in stderr capture, all actors stopped

### Wallet-app smoke test

After `make down` → `make up` → `make down`, the Docker logs should show:
- Zero `[FATAL] all coroutines are asleep - deadlock` lines
- Zero `Swoole\Error: API must be called in the coroutine` lines
- Zero `Worker_reactor_try_to_exit() ERRNO 9101` lines

This is the user-visible success criterion.

## Migration

Additive at the mailbox level: existing `Mailbox<T>` implementations gain a `close()` no-op default if necessary, but the two ships-with-Nexus impls (`SwooleMailbox`, `InMemoryMailbox`) get real implementations. Existing tests don't change.

The `ActorSystem::shutdown()` API doesn't change. Callers see the same `Duration $timeout` parameter; behavior is "stricter and quieter."

## Open questions (resolved during design)

| Question | Decision |
|---|---|
| Should `enqueue()` outside coroutine wrap-in-coroutine or queue-locally? | Wrap. Local buffering complicates the back-pressure story and only matters for the no-coroutine window. |
| Should the mailbox's `close()` drain or discard? | Drain — let in-flight messages process before shutdown completes. The deadline handles the case where actors refuse to drain. |
| Should `ThreadQueueTransport::stop()` flush pending envelopes? | No. By the time transports stop, the actor system is already torn down — pending envelopes have nowhere to go. They get dropped. |
| Should we add `Cancellable` to `Runtime::scheduleOnce`? | Out of scope. Existing `Cancellable` return value already exists; this plan doesn't touch it. |
| Does `Mailbox::close()` belong on the interface or just impls? | Interface. Any future runtime (e.g., true async drivers) will need cancellation; baking it in now is cheap. |
