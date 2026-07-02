# Graceful Shutdown Implementation Plan (Plan 5)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Eliminate the shutdown-time `[FATAL] all coroutines are asleep - deadlock` and `Uncaught Swoole\Error: API must be called in the coroutine` errors. Swoole worker stop should drain gracefully within the configured timeout, with no orphaned coroutines and no force-termination warnings.

**Architecture:** Five layered fixes — `Mailbox::close()` for cancellable receive, coroutine-context-safe `SwooleMailbox::enqueue()`, `ThreadQueueTransport::stop()` flag, deadline-driven `ActorSystem::shutdown()` orchestration, and a `WorkerStop` hook that defensively wraps the shutdown in `Coroutine::create()`. Each task is small and self-contained; the failure modes today are well-isolated so each fix's effect is independently observable.

**Tech Stack:** PHP 8.5 strict, Psalm strict-level 1, PHPCS PER-CS2.0 + Slevomat, PHPUnit 13, GrumPHP. Branch `feat/nexus-doctrine` (or cut a fresh `feat/graceful-shutdown` if preferred — this work is independent of Plans 1–4).

**Depends on:** Nothing new. Plans 1–4 don't introduce any dependency for this work; the shutdown deadlocks are pre-existing in `nexus-runtime-swoole` and `nexus-http-server-swoole`.

---

## Spec → Plan map

| Spec section | Tasks |
|---|---|
| Cancellable mailbox (`close()` on interface + impls) | T1–T3 |
| Coroutine-context-safe `SwooleMailbox::enqueue` | T4 |
| `ThreadQueueTransport::stop()` | T5 |
| `ActorSystem::shutdown()` orchestration | T6 |
| `WorkerStop` hook coroutine-wrap | T7 |
| Integration test (no-deadlock under load) | T8 |
| Docs + final gate | T9–T10 |

---

## File structure

**Modified files in `packages/nexus-core/`:**

```
packages/nexus-core/src/Mailbox/Mailbox.php                    add close()
packages/nexus-core/src/Mailbox/InMemoryMailbox.php            close() impl
packages/nexus-core/src/Actor/ActorSystem.php                  deadline-driven shutdown
packages/nexus-core/src/Actor/ActorCell.php                    handle closed-mailbox dequeue
```

**Modified files in `packages/nexus-runtime-swoole/`:**

```
packages/nexus-runtime-swoole/src/SwooleMailbox.php            close() + coroutine-safe enqueue
```

**Modified files in `packages/nexus-worker-pool-swoole/`:**

```
packages/nexus-worker-pool-swoole/src/Transport/ThreadQueueTransport.php      stop() + loop flag
```

**Modified files in `packages/nexus-http-server-swoole/`:**

```
packages/nexus-http-server-swoole/src/Bridge/SwooleServerEventBinder.php     wrap shutdown in coroutine
```

**New test files:**

```
packages/nexus-core/tests/Unit/Mailbox/InMemoryMailboxCloseTest.php
packages/nexus-runtime-swoole/tests/Unit/SwooleMailboxCloseTest.php
packages/nexus-worker-pool-swoole/tests/Unit/Transport/ThreadQueueTransportStopTest.php
packages/nexus-core/tests/Unit/Actor/ActorSystemShutdownDeadlineTest.php
tests/Integration/Fiber/CleanShutdownTest.php
tests/Integration/Swoole/CleanShutdownUnderLoadTest.php
```

**Modified docs:**

```
website/docs/core-concepts/lifecycle.md                       shutdown lifecycle section
website/docs/runtimes/swoole.md                               note on graceful drain
CLAUDE.md                                                     ActorSystem::shutdown semantics
```

---

## Conventions

Same as prior plans:
- Docker for everything (`docker compose exec -T php-fiber|php-swoole vendor/bin/phpunit …`).
- GrumPHP gates each commit; **GPG broken — `git commit --no-gpg-sign`**; no `Co-Authored-By`.
- Leave alone: `.deptrac.cache`, `packages/nexus-http-toolkit/src/Middleware/BodySizeLimitMiddleware.php`.
- All classes `final`; value objects `readonly`. PER-CS2.0 + Slevomat.

---

## Task 1: `Mailbox::close()` interface method

**Files:**
- Modify: `packages/nexus-core/src/Mailbox/Mailbox.php` — add method signature
- Verify: list every class that implements `Mailbox<T>` and ensure each is updated in T2 (in-memory) and T4 (swoole)

### Step 1: Inspect current interface

```bash
cat packages/nexus-core/src/Mailbox/Mailbox.php
grep -rn "implements Mailbox\|extends.*Mailbox" packages/ tests/ 2>/dev/null | head -10
```

Identify all impls. Likely: `InMemoryMailbox` (Fiber), `SwooleMailbox` (Swoole), possibly a test fake.

### Step 2: Add the method signature

```php
/**
 * Close the mailbox. Subsequent enqueue() calls return
 * EnqueueResult::Dropped without raising. Blocked dequeueBlocking()
 * calls return Option::none() once the mailbox drains.
 *
 * Idempotent — calling close() on an already-closed mailbox is a no-op.
 */
public function close(): void;
```

### Step 3: Stub the method on every impl so the build compiles

Each implementation gets a placeholder body that T2/T4 will replace:

```php
public function close(): void
{
    // wired in Plan 5 T2/T4
}
```

### Step 4: Verify the whole test suite still compiles

```bash
docker compose exec -T php-fiber vendor/bin/phpunit --testsuite=unit
```

Expected: all green.

### Step 5: Commit

```bash
git add packages/nexus-core/src/Mailbox/Mailbox.php packages/nexus-core/src/Mailbox/InMemoryMailbox.php packages/nexus-runtime-swoole/src/SwooleMailbox.php
git commit --no-gpg-sign -m "feat(core): add Mailbox::close() interface method (stub impls)"
```

---

## Task 2: `InMemoryMailbox::close()` implementation

**Files:**
- Modify: `packages/nexus-core/src/Mailbox/InMemoryMailbox.php`
- Create: `packages/nexus-core/tests/Unit/Mailbox/InMemoryMailboxCloseTest.php`

### Step 1: Write failing test

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Unit\Mailbox;

use Monadial\Nexus\Core\Mailbox\EnqueueResult;
use Monadial\Nexus\Core\Mailbox\Envelope;
use Monadial\Nexus\Core\Mailbox\InMemoryMailbox;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(InMemoryMailbox::class)]
final class InMemoryMailboxCloseTest extends TestCase
{
    #[Test]
    public function closeReturnsDroppedOnSubsequentEnqueue(): void
    {
        $mailbox = $this->mailbox();
        $mailbox->close();

        $result = $mailbox->enqueue($this->envelope());

        self::assertSame(EnqueueResult::Dropped, $result);
    }

    #[Test]
    public function closeIsIdempotent(): void
    {
        $mailbox = $this->mailbox();
        $mailbox->close();
        $mailbox->close();   // must not throw

        self::assertTrue(true);
    }

    #[Test]
    public function existingItemsDrainAfterClose(): void
    {
        $mailbox = $this->mailbox();
        $envelope = $this->envelope();
        $mailbox->enqueue($envelope);
        $mailbox->close();

        $popped = $mailbox->dequeue();
        $emptyAfter = $mailbox->dequeue();

        self::assertNotNull($popped);
        self::assertNull($emptyAfter);
    }

    private function mailbox(): InMemoryMailbox
    {
        // Adapt constructor signature to whatever InMemoryMailbox actually
        // requires (mailbox config, ActorPath, etc.). Read the file first.
        return new InMemoryMailbox(/* args */);
    }

    private function envelope(): Envelope
    {
        return Envelope::user(new stdClass(), /* sender */, /* target */);
    }
}
```

Adapt the test setup to the actual constructor signatures by reading the existing `InMemoryMailboxTest` (whatever it's called).

### Step 2: Implementation

Add a `private bool $closed = false;` field. Update `close()`:

```php
public function close(): void
{
    $this->closed = true;
}
```

Update `enqueue()`:

```php
if ($this->closed) {
    return EnqueueResult::Dropped;
}
```

Update `dequeue()` / `dequeueBlocking()` to return null when the queue is empty AND closed (so drain-then-stop semantics work — closed-but-not-empty still yields items).

### Step 3: Verify + commit

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-core/tests/Unit/Mailbox/InMemoryMailboxCloseTest.php
git add packages/nexus-core/src/Mailbox/InMemoryMailbox.php packages/nexus-core/tests/Unit/Mailbox/InMemoryMailboxCloseTest.php
git commit --no-gpg-sign -m "feat(core): InMemoryMailbox::close() drains then drops"
```

---

## Task 3: Actor cell handles closed-mailbox dequeue

**Files:**
- Modify: `packages/nexus-core/src/Actor/ActorCell.php`

### Step 1: Locate the message loop

```bash
grep -n "dequeueBlocking\|spawnMessageLoop" packages/nexus-core/src/Actor/ActorCell.php
```

The existing loop probably looks like:

```php
while (!$this->stopped()) {
    $envelope = $this->mailbox->dequeueBlocking($timeout);
    if ($envelope === null) { continue; }
    // process...
}
```

### Step 2: Treat closed-empty as a stop signal

Add a check after `dequeueBlocking` returns null:

```php
$envelope = $this->mailbox->dequeueBlocking($timeout);
if ($envelope === null) {
    if ($this->mailbox->isClosed()) {
        return;   // mailbox closed and drained — exit the loop
    }
    continue;
}
```

This requires `Mailbox::isClosed(): bool` — add to the interface in T1 (or here if missed).

### Step 3: No new test (covered by T8 integration). Commit

```bash
git add packages/nexus-core/src/Actor/ActorCell.php packages/nexus-core/src/Mailbox/Mailbox.php
git commit --no-gpg-sign -m "feat(core): ActorCell exits loop on closed-empty mailbox"
```

---

## Task 4: `SwooleMailbox::close()` + coroutine-context-safe `enqueue`

**Files:**
- Modify: `packages/nexus-runtime-swoole/src/SwooleMailbox.php`
- Create: `packages/nexus-runtime-swoole/tests/Unit/SwooleMailboxCloseTest.php`

### Step 1: Test (Swoole-only — runs under `php-swoole`)

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Swoole\Tests\Unit;

use Monadial\Nexus\Core\Mailbox\EnqueueResult;
use Monadial\Nexus\Runtime\Swoole\SwooleMailbox;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function Swoole\Coroutine\run;

#[CoversClass(SwooleMailbox::class)]
#[RequiresPhpExtension('swoole')]
final class SwooleMailboxCloseTest extends TestCase
{
    #[Test]
    public function closeUnblocksDequeue(): void
    {
        $result = 'unset';

        run(function () use (&$result): void {
            $mailbox = $this->makeMailbox();   // helper closure or static

            \Swoole\Coroutine::create(static function () use ($mailbox): void {
                \Swoole\Coroutine::sleep(0.01);
                $mailbox->close();
            });

            // Block until close()
            $result = $mailbox->dequeueBlocking(\Monadial\Nexus\Runtime\Duration::seconds(1));
        });

        self::assertNull($result);
    }

    #[Test]
    public function enqueueOutsideCoroutineDoesNotCrash(): void
    {
        $mailbox = $this->makeMailbox();

        // Called directly from main, no coroutine context — must not throw.
        $result = $mailbox->enqueue($this->envelope());

        self::assertSame(EnqueueResult::Accepted, $result);
    }
}
```

### Step 2: Implementation

```php
public function close(): void
{
    if ($this->closed) {
        return;
    }
    $this->closed = true;
    $this->channel->close();
}

public function enqueue(Envelope $envelope): EnqueueResult
{
    if ($this->closed) {
        return EnqueueResult::Dropped;
    }

    if (\Swoole\Coroutine::getCid() === -1) {
        // Outside coroutine context (e.g. WorkerStop hook). Wrap the
        // push in a fresh coroutine so Channel::push() has the runtime
        // it needs. Caller sees Accepted immediately; the push lands
        // asynchronously inside the new coroutine.
        $channel = $this->channel;
        \Swoole\Coroutine::create(static function () use ($channel, $envelope): void {
            $channel->push($envelope, 0.001);
        });
        return EnqueueResult::Accepted;
    }

    $pushed = $this->channel->push($envelope, 0.001);
    return $pushed ? EnqueueResult::Accepted : EnqueueResult::Dropped;
}

public function dequeueBlocking(Duration $timeout): ?Envelope
{
    $result = $this->channel->pop($timeout->toSecondsFloat());

    // Channel::pop() returns false on close OR timeout.
    return $result === false ? null : $result;
}

public function isClosed(): bool
{
    return $this->closed;
}
```

### Step 3: Verify + commit

```bash
docker compose exec -T php-swoole vendor/bin/phpunit packages/nexus-runtime-swoole/tests/Unit/SwooleMailboxCloseTest.php
git add packages/nexus-runtime-swoole/src/SwooleMailbox.php packages/nexus-runtime-swoole/tests/Unit/SwooleMailboxCloseTest.php
git commit --no-gpg-sign -m "feat(runtime-swoole): SwooleMailbox close() + coroutine-safe enqueue"
```

---

## Task 5: `ThreadQueueTransport::stop()`

**Files:**
- Modify: `packages/nexus-worker-pool-swoole/src/Transport/ThreadQueueTransport.php`
- Create: `packages/nexus-worker-pool-swoole/tests/Unit/Transport/ThreadQueueTransportStopTest.php`

### Step 1: Inspect current loop

```bash
grep -n "startReceiveLoop\|Coroutine::sleep\|stopping" packages/nexus-worker-pool-swoole/src/Transport/ThreadQueueTransport.php
```

### Step 2: Add `private bool $stopping = false;` and a `stop()` method

```php
public function stop(): void
{
    $this->stopping = true;
}
```

Inside the receive loop:

```php
while (!$this->stopping) {
    $envelope = $this->queue->pop(0.01);   // 10ms poll
    if ($envelope !== null) {
        ($this->handler)($envelope);
        continue;
    }
    \Swoole\Coroutine::sleep($this->backoff());
}
```

### Step 3: Test

```php
#[Test]
public function stopExitsReceiveLoop(): void
{
    run(function (): void {
        $transport = $this->makeTransport();
        $transport->startReceiveLoop(static fn() => null);

        \Swoole\Coroutine::create(static function () use ($transport): void {
            \Swoole\Coroutine::sleep(0.02);
            $transport->stop();
        });

        // Should exit within ~30ms.
        \Swoole\Coroutine::sleep(0.05);

        self::assertTrue($transport->isStopped());
    });
}
```

### Step 4: Verify + commit

```bash
docker compose exec -T php-swoole vendor/bin/phpunit packages/nexus-worker-pool-swoole/tests/Unit/Transport/ThreadQueueTransportStopTest.php
git add packages/nexus-worker-pool-swoole/src/Transport/ThreadQueueTransport.php packages/nexus-worker-pool-swoole/tests/Unit/Transport/ThreadQueueTransportStopTest.php
git commit --no-gpg-sign -m "feat(worker-pool-swoole): ThreadQueueTransport::stop() exits receive loop"
```

---

## Task 6: `ActorSystem::shutdown()` orchestration

**Files:**
- Modify: `packages/nexus-core/src/Actor/ActorSystem.php`
- Create: `packages/nexus-core/tests/Unit/Actor/ActorSystemShutdownDeadlineTest.php`

### Step 1: Inspect current shutdown

```bash
grep -nE "public function shutdown|public function stop" packages/nexus-core/src/Actor/ActorSystem.php
```

### Step 2: Replace with deadline-driven sequence

```php
public function shutdown(Duration $timeout): void
{
    if ($this->stopping) {
        return;
    }
    $this->stopping = true;

    $deadlineNanos = hrtime(true) + $timeout->toNanos();

    foreach ($this->children as $child) {
        $child->tell(new PoisonPill());
    }

    // Cooperative wait until either all children stop or the deadline expires.
    while (hrtime(true) < $deadlineNanos && !$this->allChildrenStopped()) {
        $this->runtime->yield();
    }

    // Force-close stragglers. The actor cell's message loop exits naturally
    // when its mailbox returns closed-empty (T3 wiring).
    foreach ($this->aliveChildren() as $child) {
        $child->cell()->mailbox()->close();
    }

    // Tear down transports last so any final messages have somewhere to go.
    foreach ($this->transports ?? [] as $transport) {
        $transport->stop();
    }
}
```

`runtime->yield()` is the existing `Runtime::yield()` method per `CLAUDE.md`.

### Step 3: Test — actor that ignores PoisonPill gets force-closed

```php
#[Test]
public function unresponsiveActorIsForceClosedAtDeadline(): void
{
    $runtime = new TestRuntime();
    $system = ActorSystem::create('test', $runtime);

    $ref = $system->spawn(Props::fromBehavior(
        Behavior::receive(static fn($ctx, $msg) => Behavior::same())   // ignores PoisonPill
    ), 'ignorant');

    $start = hrtime(true);
    $system->shutdown(Duration::millis(50));
    $elapsed = (hrtime(true) - $start) / 1_000_000;

    self::assertFalse($ref->isAlive());
    self::assertLessThan(100, $elapsed, 'shutdown should complete within ~2x deadline');
}
```

### Step 4: Verify + commit

```bash
docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-core/tests/Unit/Actor/ActorSystemShutdownDeadlineTest.php
git add packages/nexus-core/src/Actor/ActorSystem.php packages/nexus-core/tests/Unit/Actor/ActorSystemShutdownDeadlineTest.php
git commit --no-gpg-sign -m "feat(core): deadline-driven ActorSystem::shutdown() with force-close"
```

---

## Task 7: HTTP server `WorkerStop` hook coroutine-wrap

**Files:**
- Modify: `packages/nexus-http-server-swoole/src/Bridge/SwooleServerEventBinder.php`

### Step 1: Find the bindWorkerStop method

```bash
grep -n "bindWorkerStop\|WorkerStop" packages/nexus-http-server-swoole/src/Bridge/SwooleServerEventBinder.php
```

### Step 2: Wrap the shutdown call defensively

Current code:
```php
$server->on('WorkerStop', function () use ($system, $timeout): void {
    $system->shutdown($timeout);
});
```

Replace with:
```php
$server->on('WorkerStop', static function () use ($system, $timeout): void {
    if (\Swoole\Coroutine::getCid() === -1) {
        \Swoole\Coroutine::create(static fn() => $system->shutdown($timeout));
        return;
    }
    $system->shutdown($timeout);
});
```

T4's coroutine-safe `enqueue` already covers the case where this doesn't fire — this is the defensive belt over T4's suspenders.

### Step 3: Commit

No unit test — covered by T8 integration. Commit:

```bash
git add packages/nexus-http-server-swoole/src/Bridge/SwooleServerEventBinder.php
git commit --no-gpg-sign -m "fix(http-server-swoole): wrap WorkerStop shutdown in coroutine if needed"
```

---

## Task 8: Integration test — clean shutdown under load

**Files:**
- Create: `tests/Integration/Fiber/CleanShutdownTest.php`
- Create: `tests/Integration/Swoole/CleanShutdownUnderLoadTest.php`

### Step 1: Fiber test

Spawn 100 actors via `FiberRuntime`, send each a message, call `shutdown(Duration::seconds(1))`. Assert all `isAlive() === false` afterwards. Read existing Fiber integration tests for the bootstrap pattern.

### Step 2: Swoole test

Spawn 100 actors under steady tell-load (a producer coroutine fires messages at random refs every 10ms). After 500ms of load, call `shutdown(Duration::seconds(2))`. Assert all actors stopped within the deadline AND no fatal errors landed in stderr.

```php
ob_start();
$system->shutdown(Duration::seconds(2));
$stderrCapture = ob_get_clean();

self::assertStringNotContainsString('FATAL ERROR', $stderrCapture);
self::assertStringNotContainsString('all coroutines', $stderrCapture);
```

(Adapt the stderr capture to whatever Swoole-test pattern this repo uses — `Coroutine::sleep` then read a log file may be cleaner than `ob_get_clean()`.)

### Step 3: Run

```bash
docker compose exec -T php-fiber vendor/bin/phpunit tests/Integration/Fiber/CleanShutdownTest.php
docker compose exec -T php-swoole vendor/bin/phpunit tests/Integration/Swoole/CleanShutdownUnderLoadTest.php
```

### Step 4: Wallet-app smoke verify

```bash
cd examples/nexus-wallet-app && make up && sleep 5 && make down 2>&1 | tee /tmp/wallet-shutdown.log
grep -cE 'FATAL|coroutine|deadlock|Worker_reactor_try_to_exit' /tmp/wallet-shutdown.log
# Expected: 0
```

This is the user-visible success criterion the original log inspection demanded.

### Step 5: Commit

```bash
git add tests/Integration/Fiber/CleanShutdownTest.php tests/Integration/Swoole/CleanShutdownUnderLoadTest.php
git commit --no-gpg-sign -m "test(core): graceful shutdown integration tests (Fiber + Swoole under load)"
```

---

## Task 9: Documentation

**Files:**
- Modify: `website/docs/core-concepts/lifecycle.md` — add an "ActorSystem shutdown" section
- Modify: `website/docs/runtimes/swoole.md` — note the graceful drain
- Modify: `CLAUDE.md` — `ActorSystem::shutdown(Duration)` semantics

### `lifecycle.md` section to add

```markdown
## ActorSystem shutdown

`ActorSystem::shutdown(Duration $timeout)` is **deadline-driven**:

1. Mark the system as `stopping` (no new spawns accepted).
2. Send `PoisonPill` to every root child.
3. Cooperatively yield until either all actors stop **or** the deadline expires.
4. Force-close survivors by closing their mailbox — the actor cell's
   message loop exits naturally when the mailbox returns closed-empty.
5. Tear down transports (in worker-pool setups).

The timeout is a *hard* deadline. An actor that ignores `PoisonPill` is
force-closed when the deadline passes, not waited for indefinitely.
`PostStop` still runs as part of the force-close path, so resource
cleanup is correct.

Under Swoole, the runtime hook installed by `DoctrineBootstrap::enable()`
means all blocked PDO calls are coroutine-suspended — they're woken when
the mailbox closes. Shutdown completes within one mailbox-poll cycle
(~10ms) plus the time to drain in-flight handlers.
```

### `CLAUDE.md` update

Find the `ActorSystem` section. Update the `shutdown` description:

> `shutdown(Duration $timeout): void` — Deadline-driven graceful shutdown.
> Broadcasts `PoisonPill` to root children, cooperatively yields until
> they stop, force-closes survivors at the deadline. `PostStop` fires
> on all actors. Idempotent — calling shutdown twice is safe.

### Commit

```bash
git add website/docs/core-concepts/lifecycle.md website/docs/runtimes/swoole.md CLAUDE.md
git commit --no-gpg-sign -m "docs: ActorSystem shutdown semantics + Swoole graceful drain"
```

---

## Task 10: Final repo-wide gate + wallet-app smoke

- [ ] **Step 1: Unit suites**

```bash
docker compose exec -T php-fiber vendor/bin/phpunit --testsuite=unit
docker compose exec -T php-swoole vendor/bin/phpunit --testsuite=unit-swoole
docker compose exec -T php-fiber vendor/bin/phpunit --testsuite=psalm
```

- [ ] **Step 2: Linters**

```bash
docker compose exec -T php-fiber vendor/bin/phpcs
docker compose exec -T php-fiber vendor/bin/php-cs-fixer fix --dry-run
docker compose exec -T php vendor/bin/psalm --no-cache
docker compose exec -T php php -d error_reporting="E_ALL & ~E_DEPRECATED" vendor/bin/deptrac analyze
```

- [ ] **Step 3: Integration**

```bash
make test-fiber
make test-swoole
make test-doctrine
```

- [ ] **Step 4: Wallet-app shutdown smoke**

```bash
cd examples/nexus-wallet-app
make build && make install && make up && sleep 5
docker compose logs app | wc -l > /tmp/before.txt
make down 2>&1 | tee /tmp/wallet-shutdown.log
docker compose logs app | grep -cE 'FATAL|coroutines|deadlock|ERRNO 9101' || true
# Expected output: 0 (zero fatal-error lines in the shutdown logs)
```

- [ ] **Step 5: Push (with user approval)**

Ask before pushing.

---

## Self-review checklist

- [ ] No `TBD` / `TODO` / `FIXME` in this plan: `grep -E 'TBD|TODO|FIXME' docs/superpowers/plans/2026-06-17-graceful-shutdown.md`
- [ ] Every spec section maps to a task.
- [ ] Method names consistent: `Mailbox::close()`, `Mailbox::isClosed()`, `SwooleMailbox::close()`, `ThreadQueueTransport::stop()`, `ActorSystem::shutdown(Duration)`.
- [ ] Commit prefixes: `feat(core):`, `feat(runtime-swoole):`, `feat(worker-pool-swoole):`, `fix(http-server-swoole):`, `test(core):`, `docs:`.
- [ ] No breaking changes to existing public APIs (only additive interface methods + behavior change to `shutdown` that's stricter, not laxer).
- [ ] Fiber path stays in lockstep with Swoole — `close()` works identically for both.

---

## Follow-ups (out of scope, mentioned for tracking)

- **`Cancellable` semantics for `Runtime::scheduleOnce`** — the existing `Cancellable` works but isn't formalized as a cross-runtime contract.
- **Cluster-wide graceful shutdown** — once multi-node clustering ships in `nexus-cluster`, propagate the shutdown signal across nodes.
- **HTTP request drain coordination** — currently Swoole handles inbound HTTP drain natively; if the actor system shutdown deadline trips first, in-flight handlers see truncated EM connections. Consider gating shutdown on "no in-flight requests" if it matters.
