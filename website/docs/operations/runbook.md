---
title: On-Call Runbook
related:
  - operations/troubleshooting
  - operations/graceful-shutdown
  - operations/metrics
---

# On-Call Runbook

Ten on-call scenarios with the symptom you see, what to check next, and how to recover. Each entry is self-contained so you can jump directly to the relevant section from an alert.

---

### 1. Actor system not draining at shutdown

**Symptom:** Deploy or pod termination takes the full `terminationGracePeriodSeconds` before the process exits. Logs show actors still alive after `PoisonPill` broadcast.

**Check:**
- Is any actor blocking inside a handler? Look for `sleep()`, synchronous HTTP, or PDO calls in handler code. A blocked fiber cannot process `PoisonPill`.
- Is the shutdown timeout shorter than the slowest actor's drain time? Check `$config->shutdownTimeout` in your server config.
- Are there actors with large mailbox backlogs? Check dead-letter counts at shutdown — messages dropped to dead letters indicate the backlog was not drained.

**Action:**
1. Increase `shutdownTimeout` to match the 99th-percentile handler duration.
2. If blocking calls are present, suppress them or move them to `scheduleOnce` (see [troubleshooting: blocking call violation](./troubleshooting.md#15-blocking-call-psalm-rule-violation)).
3. For immediate recovery on a stuck pod: scale the deployment to add a new replica, then force-delete the stuck pod (`kubectl delete pod --grace-period=0`).

---

### 2. Mailbox overflow rate spiking

**Symptom:** `MailboxOverflowException` count is rising in logs, or dead-letter count spikes without a corresponding error rate.

**Check:**
- Which actor is overflowing? Search logs for `MailboxOverflowException` to find the actor path.
- What is the overflow strategy? `DropNewest` and `DropOldest` discard silently; `ThrowException` logs on every drop; `Backpressure` blocks the sender.
- Is the actor processing slower than it was (new deployment, DB slowdown, lock contention)?

**Action:**
1. Short-term: increase mailbox capacity via `MailboxConfig::bounded($newCapacity, ...)`.
2. For stateless actors: spin up more instances behind a router actor to fan out the work.
3. For stateful actors: investigate the handler bottleneck — profile with Xdebug or add `hrtime()` instrumentation around the slow path.
4. Switch to `Backpressure` strategy while investigating so senders get back-pressure instead of silent drops.

---

### 3. Dead letter spike

**Symptom:** Dead-letter log entries appear at a rate significantly above baseline.

**Check:**
- Are actors stopping unexpectedly? A dead-letter spike often follows an actor crash — messages sent to a stopped actor land in dead letters.
- Are `tell()` calls made to stale references? Check if any code stores `ActorRef` objects in caches or long-lived structures without checking `isAlive()`.
- Did a deployment restart actors mid-flight? Messages in transit at restart land in dead letters.

**Action:**
1. Identify the target actor path from the dead-letter log entries.
2. Check if that actor is alive: if not, find why it stopped (supervision tree logs, `ChildFailed` signal).
3. If the actor is crashing on a specific message, fix the handler or add supervision to restart it.
4. If the spike is deployment-related and transient, no action required — monitor that it returns to baseline.

**Reading the signal:** the `nexus.actor_system.dead_letters` gauge is a **monotonic total** (`DeadLetterRef::total()`) — it counts every dead letter and never resets, so alert on its *rate of change*, not its absolute value. For inspection, `DeadLetterRef::captured()` retains only the most recent ~1000 messages (bounded so it cannot leak memory under a flood); subscribe to the PSR-14 `MessageDeadLettered` event to capture every one without polling. A `tell()` whose mailbox is closed or full is now dead-lettered consistently rather than silently dropped, so a genuine delivery-failure spike is fully visible here.

---

### 4. Ask timeout cascade

**Symptom:** Multiple `AskTimeoutException` errors in a short window, affecting several actors or request handlers that use the ask pattern.

**Check:**
- Is the actor being asked still alive? A stopped actor never replies; every pending ask times out.
- Is the actor's mailbox backed up? If the actor is overloaded, replies arrive after the caller's timeout window closes.
- Is there a downstream bottleneck (DB slow query, external HTTP call) that extended handler latency?

**Action:**
1. Check the target actor's mailbox depth and handler latency.
2. If the actor is down, restart it or check its supervision strategy — `MaxRetriesExceededException` stops an actor permanently.
3. Increase ask timeouts as a temporary measure while investigating the root cause.
4. Consider replacing synchronous ask with fire-and-forget tell plus a callback actor for non-latency-sensitive flows.

---

### 5. Worker thread crash

**Symptom:** A worker pool thread exits. In thread-mode Swoole, this is logged as `Worker X crashed` or the worker ID stops appearing in request logs.

**Check:**
- Did `$system->shutdown()` throw an unhandled exception inside the worker's watchdog? Check worker-specific log lines around the crash time.
- Did a `Fatal error` occur (OOM, stack overflow) that bypassed the actor supervision tree?
- Is the `WorkerStartHandler::onWorkerStart()` implementation throwing during setup?

**Action:**
1. Swoole restarts crashed workers automatically in worker mode. In thread mode, the thread does not auto-restart — restart the server process.
2. Reproduce locally with the same worker count and message volume: `docker compose exec php-swoole php bin/server.php`.
3. If OOM: increase memory limit in `php.ini`, or reduce per-request allocations.
4. Add a `try/catch` in `onWorkerStart()` with explicit logging so startup failures are visible.

---

### 6. Doctrine connection pool exhaustion

**Symptom:** `PoolExhaustedException` appearing in logs, request error rate rising, database connections maxed out.

**Check:**
- What is `ConnectionPoolConfig::size()`? Is it set lower than the database's `max_connections`?
- Are connections being held too long? A handler that does multiple round-trips without releasing the connection back to the pool (or that has a long transaction) keeps it checked out for the full request duration.
- Is there a deadlock or slow query holding a connection open? Check `SHOW PROCESSLIST` on the database.

**Action:**
1. Short-term: restart the server to flush any leaked connections.
2. Verify pool size matches your database's `max_connections` minus headroom for admin/migration connections.
3. Check for handlers that call `$em->beginTransaction()` without a matching `commit()` or `rollback()` — these hold connections indefinitely.
4. Add connection acquisition timeout logging: `ConnectionPoolConfig::withAcquireTimeout(Duration::seconds(5))` so slow acquisitions appear in logs before they cascade.

---

### 7. `ReceiveTimeout` firing unexpectedly

**Symptom:** Actors are passivating or stopping earlier than expected. Log shows `ReceiveTimeout` signals firing.

**Check:**
- Which actors call `$ctx->setReceiveTimeout()`? This is an opt-in feature — only actors that explicitly set a timeout receive the `ReceiveTimeout` signal.
- Is the configured duration shorter than the expected message interval? A burst-quiet message pattern can leave the actor idle for longer than the timeout between bursts.
- Was the timeout set during setup and never cleared? `$ctx->setReceiveTimeout(null)` disables it.

**Action:**
1. Increase the `ReceiveTimeout` duration to exceed the maximum expected idle window.
2. If passivation is the goal but triggering too aggressively, add a check before stopping: only stop if business-level state is empty.
3. If `ReceiveTimeout` is firing on an actor that should not have it set, search for `setReceiveTimeout` calls in the actor hierarchy — it can be set in a parent's setup closure.

---

### 8. Swoole reactor exit timeout

**Symptom:** On shutdown, the process hangs at the Swoole reactor shutdown phase. Logs show `Server BeforeShutdown` fired but the process does not exit within the expected window.

**Check:**
- Is the `BeforeShutdown` watchdog completing? Look for `Worker ActorSystem shutdown complete` log lines from each worker.
- Are there coroutines still running outside of the actor system (e.g. a custom `Coroutine::create()` that was never cancelled)?
- Is the `shutdownTimeout` configured in `SwooleThreadConfig` large enough for actors to drain?

**Action:**
1. Search application code for `Coroutine::create()` calls outside of the Nexus actor system — these must be self-terminating or cancelled explicitly before server shutdown.
2. Increase `SwooleThreadConfig::withShutdownTimeout()`.
3. If the hang is reproducible, attach `strace` or check `Swoole::stats()` for coroutine counts after BeforeShutdown fires.

---

### 9. Persistence writer conflict (split-brain)

**Symptom:** `WriterConflictException: Writer conflict detected for persistence ID 'Order-abc'` in logs. Events from two different writer IDs appear interleaved in the event store.

**Cause:** Two actor system instances are writing to the same `PersistenceId`. This is a violation of the single-writer principle: each persistence ID must be owned by exactly one `ActorSystem` at a time.

**Check:**
- Are two pods writing to the same database? Blue-green deployments with shared event stores hit this during the overlap window.
- Did a pod restart without the old instance fully shutting down? The new instance generates a new writer ULID and detects the previous writer's events.

**Action:**
1. Ensure only one pod is active per persistence ID. Use rolling deployments, not blue-green, for event-sourced services.
2. If the conflict is from a restart, check the `ReplayFilter` mode. `RepairByDiscardOld` recovers using only the latest writer's events **for that replay** — the interleaved events remain in the store, so it masks the conflict rather than repairing it. Use it only after confirming the older writer is gone; the durable fix is step 3 plus enforcing a single writer.
3. Inspect the event store for interleaved sequences: events with two different `writer_id` values on the same `persistence_id`.
4. After recovery, rotate the persistence ID or archive the conflicted sequence before resuming normal operation.

**See also:** [Single-writer principle](../persistence/single-writer.md), [Persistence overview](../persistence/overview.md)

---

### 10. Graceful-shutdown deadline missed

**Symptom:** The process exits with exit code 137 (SIGKILL from Kubernetes) rather than a clean `0`. Pod logs show actors still processing messages at the moment of termination.

**Check:**
- Is `terminationGracePeriodSeconds` in the Kubernetes deployment longer than `shutdownTimeout`? If SIGKILL fires before Nexus finishes draining, actors are killed mid-message.
- Did the `preStop` hook add enough delay for traffic draining before SIGTERM? If traffic is still arriving at SIGTERM time, the mailbox backlog is larger at shutdown start.
- Is `shutdownTimeout` long enough for the slowest actor to drain?

**Action:**
1. Set `terminationGracePeriodSeconds` = `preStop delay` + `shutdownTimeout` + 5 s headroom.
   Example: `preStop: sleep 5` + `shutdownTimeout: 20 s` → `terminationGracePeriodSeconds: 30`.
2. Add a `preStop` lifecycle hook to drain load balancer traffic before SIGTERM fires.
3. Monitor exit codes: exit `0` means clean shutdown; exit `137` means SIGKILL (deadline missed).

**See also:** [Graceful shutdown](./graceful-shutdown.md), [Kubernetes deployment](./deployment/kubernetes.md)
