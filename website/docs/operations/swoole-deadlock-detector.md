---
title: Swoole deadlock detector false positives
related:
  - operations/troubleshooting
  - operations/graceful-shutdown
  - runtimes/swoole
  - scaling/overview
---

# Swoole deadlock detector false positives

If you deploy Nexus on `SwooleWorkerServer` (process mode) and your service is idle for tens of seconds to a few minutes, you may see the worker cycle:

```
WARNING  Worker_reactor_try_to_exit() (ERRNO 9101): worker exit timeout, forced termination
  [FATAL ERROR]: all coroutines (count: 1) are asleep - deadlock!
  [Coroutine-2]
  #0 SwooleMailbox.php(147): Swoole\Coroutine\Channel->pop()
  #1 ActorSystem.php(354): SwooleMailbox->dequeueBlocking()
  #2 ActorSystem::spawnMessageLoop()
bootstrap.INFO: booting <app>
worker startup: app compiled, accepting requests
```

Every few minutes the worker is killed and re-spawned. This page explains what's happening and what your options are.

## It's a false positive

Swoole prints the `deadlock!` line whenever the worker's reactor stops with any coroutines still suspended. Its heuristic is:

- every live coroutine is in a suspended state (`Channel::pop`, `sleep`, `recv`, …)
- no obvious external event is scheduled

If that condition holds, Swoole assumes forward progress is impossible.

Nexus actors are **listeners**: each actor's message loop calls `Channel::pop(timeout=1s)` and re-arms itself. A message from any producer (HTTP request, WebSocket frame, another actor's `tell()`) wakes it immediately. The HTTP listener socket is still live. There's no real deadlock — the system is just waiting for work.

Swoole does not distinguish "waiting on a timed channel with an external producer" from "permanently blocked". Hence the false positive.

## What actually kills the worker

Reading Swoole 6.2's source (`src/server/worker.cc`, `src/server/reactor_process.cc`), the exit sequence is:

1. Something calls `Server::stop_async_worker(worker)`.
2. The reactor removes listener sockets and sets `wait_exit = true`.
3. It repeatedly evaluates registered exit conditions. Actor coroutines pin `EXIT_CONDITION_TIMER` (the per-actor `Channel::pop(1s)` schedules a timer, so `Timer::count() > 0`).
4. After `max_wait_time` seconds (default 3), Swoole logs `SW_ERROR_SERVER_WORKER_EXIT_TIMEOUT` and forces `reactor->running = false`.
5. PHP shutdown runs; `PHPCoroutine::deactivate()` calls `deadlock_check()`, which prints the diagnostic `all coroutines (count: N) are asleep - deadlock!`.

The `deadlock!` message is a **diagnostic printed on PHP shutdown**, not the trigger. Turning it off via `Coroutine::set(['enable_deadlock_check' => false])` only hides the message; the worker still exits.

The actual trigger — `stop_async_worker` — is called from three places in Swoole 6:

- `SIGTERM` signal handler
- `has_exceeded_max_request()` if `max_request > 0`
- `SW_SERVER_EVENT_SHUTDOWN` pipe message from master (only for explicit `Server::shutdown()`, reload, or `kill_worker`)

In Nexus with `installSignalHandlers(false)` and `maxRequest(0)`, none of those *should* fire — yet in a Docker container the worker still cycles every 90–300 s. The most likely culprit is a `SIGTERM` from container orchestration or a lower-level Swoole internal we haven't pinpointed; the `WorkerStop` PHP callback does **not** fire before the exit, which means the force-termination happens below the PHP handler layer.

## What we tried that did NOT fix it

| Attempt | Rationale | Result |
|---|---|---|
| `Coroutine::set(['enable_deadlock_check' => false])` | Turn the check off. | Hides the message; worker still exits. |
| `Swoole\Timer::tick(500ms, noop)` | Keep a timer scheduled so the reactor sees active work. | Doesn't prevent `stop_async_worker`. |
| Persistent coroutine with `Coroutine::sleep(1); stats()` | Keep a coroutine "hot". | The sleeping coroutine is itself "asleep"; adds to the count. |
| `installSignalHandlers(false)` | Prevent our code from registering SIGTERM. | Already default; unrelated to root cause. |

## What actually mitigates it

- **Deploy on `SwooleThreadServer` (thread mode) instead.** Thread mode uses a different reactor lifecycle and this false positive does not fire the same way. Trade-off: thread mode rejects WebSocket channel actors at boot (`assertNoChannelRoutes`), so you must use handler-mode WebSockets (`$app->ws('/path', HandlerClass::class)`) with your own per-worker connection registry for fan-out. Suitable for services that don't need channel-actor broadcast.
- **Accept the cycle for low-traffic services.** During active traffic (HTTP requests, WebSocket messages) the worker never idles into this state. It only fires during quiet windows. The master respawns the worker automatically. If you can tolerate a ~90 s p99 for reconnecting WebSocket clients (React can auto-reconnect), the cycle is cosmetic.
- **Run the game/session server in single-process mode with `SWOOLE_BASE`.** In BASE mode there is no manager and the "worker" is the master; the exit path is different.
- **Persistent actor state in Postgres/Redis.** Anything actor-local that isn't persisted is lost each cycle. Design your actors so they survive a cold respawn — this is the Nexus [passivation](../best-practices/passivation.md) pattern. If a game session's authoritative state is in Postgres (as tic-tac-toe's `GameSession` is), a worker respawn recovers cleanly: the next command reload the row.

## What we recommend

For production Nexus services in worker mode:

1. **Persist authoritative state.** Assume any actor may be respawned; use `EntityBehavior` or event-sourcing so recovery is transparent. See [Single-writer aggregates](../best-practices/single-writer-aggregates.md).
2. **Design WebSocket clients to auto-reconnect.** A React or Swoole client should back off + reconnect on unexpected close. Your game/chat/dashboard should be robust to a 1 s disconnect window every few minutes.
3. **Alert on the frequency of `Worker_reactor_try_to_exit`, not on its presence.** If it fires more than once per minute you have a real problem; once every 3 minutes on an idle service is a Swoole quirk, not a real deadlock.
4. **Consider thread mode + handler-mode WS with an external pub/sub for fan-out** if your workload can't tolerate the cycle. Sample stack: Postgres `LISTEN/NOTIFY` fan-out, or Redis pub/sub.

## Upstream tracking

Reproducing the false positive requires an idle Swoole 6.2 worker with a `Channel::pop`-based coroutine. Upstream issue candidates:

- Swoole 6 `enable_deadlock_check` is a Coroutine subsystem flag; it does not gate the `stop_async_worker` path.
- The reactor's `EXIT_CONDITION_TIMER` returns `false` while any timer is scheduled — which our actors do implicitly via `Channel::pop(1s)`. Yet Swoole still calls the exit path. The gap is worth an upstream issue.

If you have time to file one, the minimal reproducer is:

```php
Co\run(function () {
    // Nexus actor loop shape
    $ch = new Swoole\Coroutine\Channel(1);
    Coroutine::create(function () use ($ch) {
        while (true) {
            $msg = $ch->pop(1.0);
            if ($msg === false) continue;
            // never receives — no producer
        }
    });

    // Idle here for 90+ seconds inside a Swoole HTTP server context
    // and observe Worker_reactor_try_to_exit
});
```

## See also

- [Graceful shutdown](./graceful-shutdown.md) — how Nexus intentionally shuts down actors.
- [Troubleshooting](./troubleshooting.md) — other symptom-based debugging playbooks.
- [Swoole runtime](../runtimes/swoole.md) — background on the runtime this affects.
