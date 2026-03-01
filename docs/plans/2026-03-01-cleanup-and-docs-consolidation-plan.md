# Cleanup and Documentation Consolidation Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Rename stale psalm classes, remove dead placeholder files, and update all documentation (CLAUDE.md, ADRs, website) to reflect the worker-pool / cluster separation.

**Architecture:** Code cleanup first (psalm rename + dead files), then CLAUDE.md, then ADRs, then website docs in dependency order (packages → scaling → architecture → runtimes → contributing → navigation).

**Tech Stack:** PHP 8.5, Psalm, Docusaurus 3, PHPUnit 13. No new code — only renames, deletions, and documentation updates.

**Design doc:** `docs/plans/2026-03-01-cleanup-and-docs-consolidation-design.md`

---

## Task 1: Rename Psalm issue class NonSerializableClusterMessage → NonSerializableRemoteMessage

**Files:**
- Rename: `packages/nexus-psalm/src/Issue/NonSerializableClusterMessage.php` → `NonSerializableRemoteMessage.php`
- Modify: `packages/nexus-psalm/src/Hook/NonSerializableClusterMessageRule.php`

**Step 1: Create the renamed issue file**

`packages/nexus-psalm/src/Issue/NonSerializableRemoteMessage.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Issue;

use Psalm\CodeLocation;
use Psalm\Issue\PluginIssue;

final class NonSerializableRemoteMessage extends PluginIssue
{
    public function __construct(string $className, CodeLocation $codeLocation)
    {
        parent::__construct(
            'Message class "' . $className . '" sent via WorkerActorRef::tell() lacks a #[MessageType] attribute.'
            . ' Remote messages must be registered in TypeRegistry for cross-worker serialization.',
            $codeLocation,
        );
    }
}
```

**Step 2: Delete the old issue file**

```bash
rm packages/nexus-psalm/src/Issue/NonSerializableClusterMessage.php
```

**Step 3: Update the hook to use the renamed issue**

In `packages/nexus-psalm/src/Hook/NonSerializableClusterMessageRule.php`, replace:
```php
use Monadial\Nexus\Psalm\Issue\NonSerializableClusterMessage;
```
with:
```php
use Monadial\Nexus\Psalm\Issue\NonSerializableRemoteMessage;
```

And replace:
```php
                new NonSerializableClusterMessage(
```
with:
```php
                new NonSerializableRemoteMessage(
```

**Step 4: Run Psalm to confirm no errors**

```bash
docker compose exec php vendor/bin/psalm
```

Expected: `No errors found!`

**Step 5: Commit**

```bash
git add packages/nexus-psalm/src/Issue/NonSerializableRemoteMessage.php \
        packages/nexus-psalm/src/Hook/NonSerializableClusterMessageRule.php
git rm packages/nexus-psalm/src/Issue/NonSerializableClusterMessage.php
git commit -m "refactor(psalm): rename NonSerializableClusterMessage to NonSerializableRemoteMessage"
```

---

## Task 2: Rename Psalm hook class NonSerializableClusterMessageRule → NonSerializableRemoteMessageRule

**Files:**
- Rename: `packages/nexus-psalm/src/Hook/NonSerializableClusterMessageRule.php` → `NonSerializableRemoteMessageRule.php`
- Modify: `packages/nexus-psalm/src/Plugin.php`

**Step 1: Create the renamed hook file**

Copy the content of `NonSerializableClusterMessageRule.php` to `NonSerializableRemoteMessageRule.php`, changing only the class name:

`packages/nexus-psalm/src/Hook/NonSerializableRemoteMessageRule.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Hook;

use Monadial\Nexus\Psalm\Issue\NonSerializableRemoteMessage;
use Monadial\Nexus\Serialization\MessageType;
use Monadial\Nexus\WorkerPool\WorkerActorRef;
use Override;
use PhpParser\Node\Expr\MethodCall;
use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\IssueBuffer;
use Psalm\Plugin\EventHandler\AfterMethodCallAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterMethodCallAnalysisEvent;
use Psalm\Type\Atomic\TNamedObject;

use function strcasecmp;
use function strtolower;

final class NonSerializableRemoteMessageRule implements AfterMethodCallAnalysisInterface
{
    private const array CHECKED_METHODS = [
        'monadial\nexus\core\actor\actorref::tell' => 0,
        'monadial\nexus\workerpool\workeractorref::tell' => 0,
    ];

    #[Override]
    public static function afterMethodCallAnalysis(AfterMethodCallAnalysisEvent $event): void
    {
        $declaringId = strtolower($event->getDeclaringMethodId());
        $argIndex = self::CHECKED_METHODS[$declaringId] ?? null;

        if ($argIndex === null) {
            return;
        }

        if (!self::callerIsRemoteRef($event)) {
            return;
        }

        $args = $event->getExpr()->getArgs();

        if (!isset($args[$argIndex])) {
            return;
        }

        $messageArg = $args[$argIndex];
        $argType = $event->getStatementsSource()->getNodeTypeProvider()->getType($messageArg->value);

        if ($argType === null) {
            return;
        }

        $codebase = $event->getCodebase();

        foreach ($argType->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof TNamedObject) {
                continue;
            }

            if (self::hasMessageTypeAttribute($codebase, $atomic->value)) {
                continue;
            }

            IssueBuffer::accepts(
                new NonSerializableRemoteMessage(
                    $atomic->value,
                    new CodeLocation($event->getStatementsSource(), $messageArg->value),
                ),
                $event->getStatementsSource()->getSuppressedIssues(),
            );
        }
    }

    private static function callerIsRemoteRef(AfterMethodCallAnalysisEvent $event): bool
    {
        $expr = $event->getExpr();

        if (!$expr instanceof MethodCall) {
            return false;
        }

        $callerType = $event->getStatementsSource()->getNodeTypeProvider()->getType($expr->var);

        if ($callerType === null) {
            return false;
        }

        foreach ($callerType->getAtomicTypes() as $atomic) {
            if ($atomic instanceof TNamedObject && strcasecmp($atomic->value, WorkerActorRef::class) === 0) {
                return true;
            }
        }

        return false;
    }

    private static function hasMessageTypeAttribute(Codebase $codebase, string $className): bool
    {
        if (!$codebase->classlike_storage_provider->has($className)) {
            return true;
        }

        $storage = $codebase->classlike_storage_provider->get($className);

        foreach ($storage->attributes as $attribute) {
            if (strcasecmp($attribute->fq_class_name, MessageType::class) === 0) {
                return true;
            }
        }

        return false;
    }
}
```

**Step 2: Delete the old hook file**

```bash
rm packages/nexus-psalm/src/Hook/NonSerializableClusterMessageRule.php
```

**Step 3: Update Plugin.php**

In `packages/nexus-psalm/src/Plugin.php`, replace:
```php
use Monadial\Nexus\Psalm\Hook\NonSerializableClusterMessageRule;
```
with:
```php
use Monadial\Nexus\Psalm\Hook\NonSerializableRemoteMessageRule;
```

And replace:
```php
            NonSerializableClusterMessageRule::class,
```
with:
```php
            NonSerializableRemoteMessageRule::class,
```

**Step 4: Run Psalm and unit tests**

```bash
docker compose exec php vendor/bin/psalm
docker compose exec php vendor/bin/phpunit --testsuite=unit
```

Expected: `No errors found!` and all tests pass.

**Step 5: Commit**

```bash
git add packages/nexus-psalm/src/Hook/NonSerializableRemoteMessageRule.php \
        packages/nexus-psalm/src/Plugin.php
git rm packages/nexus-psalm/src/Hook/NonSerializableClusterMessageRule.php
git commit -m "refactor(psalm): rename NonSerializableClusterMessageRule to NonSerializableRemoteMessageRule"
```

---

## Task 3: Remove stale .gitkeep files

**Files to remove** (directories that contain real PHP files):
```
packages/nexus-core/src/Actor/.gitkeep
packages/nexus-core/src/Exception/.gitkeep
packages/nexus-core/src/Lifecycle/.gitkeep
packages/nexus-core/src/Mailbox/.gitkeep
packages/nexus-core/src/Message/.gitkeep
packages/nexus-core/src/Supervision/.gitkeep
packages/nexus-core/tests/Unit/Actor/.gitkeep
packages/nexus-core/tests/Unit/Exception/.gitkeep
packages/nexus-core/tests/Unit/Lifecycle/.gitkeep
packages/nexus-core/tests/Unit/Mailbox/.gitkeep
packages/nexus-core/tests/Unit/Supervision/.gitkeep
packages/nexus-core/tests/Support/.gitkeep
packages/nexus-serialization/tests/Unit/.gitkeep
tests/Integration/Fiber/.gitkeep
tests/Integration/Swoole/.gitkeep
tests/Integration/Serialization/.gitkeep
```

**Keep:** `packages/nexus-core/src/Runtime/.gitkeep` — that directory is intentionally empty (reserved for future runtime interfaces).

**Step 1: Check for per-package .gitignore files**

```bash
find packages/ -name ".gitignore" -not -path "*/vendor/*"
```

Expected: no output (already confirmed none exist — root `.gitignore` handles everything).

**Step 2: Remove stale .gitkeep files**

```bash
git rm packages/nexus-core/src/Actor/.gitkeep \
       packages/nexus-core/src/Exception/.gitkeep \
       packages/nexus-core/src/Lifecycle/.gitkeep \
       packages/nexus-core/src/Mailbox/.gitkeep \
       packages/nexus-core/src/Message/.gitkeep \
       packages/nexus-core/src/Supervision/.gitkeep \
       packages/nexus-core/tests/Unit/Actor/.gitkeep \
       packages/nexus-core/tests/Unit/Exception/.gitkeep \
       packages/nexus-core/tests/Unit/Lifecycle/.gitkeep \
       packages/nexus-core/tests/Unit/Mailbox/.gitkeep \
       packages/nexus-core/tests/Unit/Supervision/.gitkeep \
       packages/nexus-core/tests/Support/.gitkeep \
       packages/nexus-serialization/tests/Unit/.gitkeep \
       tests/Integration/Fiber/.gitkeep \
       tests/Integration/Swoole/.gitkeep \
       tests/Integration/Serialization/.gitkeep
```

**Step 3: Verify directories still exist**

```bash
ls packages/nexus-core/src/Actor/ | head -3
ls tests/Integration/Fiber/ | head -3
```

Expected: PHP files listed, directories intact.

**Step 4: Commit**

```bash
git commit -m "chore: remove stale .gitkeep files from populated directories"
```

---

## Task 4: Update CLAUDE.md

**File:** `CLAUDE.md`

**Step 1: Update Design Philosophy (line ~94)**

Replace:
```
1. **Location Transparency** — Actor code is identical whether sending to local or remote actors. `ActorRef<T>` abstracts `LocalActorRef` (in-process) and `RemoteActorRef` (cross-worker).
```
With:
```
1. **Location Transparency** — Actor code is identical whether sending to local or remote actors. `ActorRef<T>` abstracts `LocalActorRef` (in-process), `WorkerActorRef` (cross-thread within a worker pool), and future remote node refs.
```

**Step 2: Update Package Dependency Graph (lines ~114-115)**

Replace:
```
├── nexus-cluster          → Core only
│   └── nexus-cluster-swoole → Cluster, Core, RuntimeSwoole
└── nexus-psalm            → (standalone Psalm plugin)
```
With:
```
├── nexus-cluster          → Core only (remote contracts)
├── nexus-worker-pool      → Core, Runtime
│   └── nexus-worker-pool-swoole → WorkerPool, Core, RuntimeSwoole
└── nexus-psalm            → (standalone Psalm plugin)
```

**Step 3: Update ActorRef implementations (line ~142)**

Replace:
```
- Implementations: `LocalActorRef<T>` (enqueues to mailbox), `RemoteActorRef<T>` (serializes + sends via transport), `DeadLetterRef` (null object)
```
With:
```
- Implementations: `LocalActorRef<T>` (enqueues to mailbox), `WorkerActorRef<T>` (sends `Envelope` directly via `WorkerTransport` — no serializer), `DeadLetterRef` (null object)
```

**Step 4: Rewrite the Clustering section (lines ~319-326)**

Replace the entire `### Clustering` section:
```markdown
### Worker Pool (nexus-worker-pool)

- `WorkerNode` — Per-worker coordinator. Routes via hash ring, handles transport envelopes, manages local actor refs.
- `ConsistentHashRing` — Maps actor names to worker IDs via CRC32 with 150 virtual nodes.
- `WorkerActorRef<T>` — Cross-worker actor reference. Sends `Envelope` objects directly via `WorkerTransport` — no serializer involved.
- `WorkerTransport` interface — `send(int $targetWorker, Envelope $envelope): void` / `listen(callable): void`. Implementations: `InMemoryWorkerTransport` (tests), `ThreadQueueTransport` (Swoole threads).
- `WorkerDirectory` interface — Maps actor paths to worker IDs. Implementations: `InMemoryWorkerDirectory` (tests), `ThreadMapDirectory` (Swoole threads).
- `WorkerPoolConfig` — `WorkerPoolConfig::withThreads(int $workerCount): self`.
- `WorkerStartHandler` interface — Implement to set up actors when a worker thread starts.

### Worker Pool Swoole (nexus-worker-pool-swoole)

- `WorkerPoolApp` — Abstract base: extend and override `configure(WorkerNode $node)`, call `static::run(WorkerPoolConfig $config)` to boot the pool.
- `WorkerPoolBootstrap` — Creates N worker threads via `Swoole\Thread\Pool`. Shares a `Thread\Map` directory and one `Thread\Queue` inbox per worker.
- `WorkerRunnable` — Thread entrypoint (`Swoole\Thread\Runnable`). Atomically claims a worker ID, boots `ActorSystem` + `SwooleRuntime`, calls `WorkerStartHandler::onWorkerStart()`.
- `ThreadQueueTransport` — Thread-safe transport backed by one `Swoole\Thread\Queue` per worker. Adaptive-poll coroutine loop with backoff: 0µs → 100µs → 1ms → 10ms.
- `ThreadMapDirectory` — Thread-safe actor directory backed by shared `Swoole\Thread\Map`.

### Cluster — Remote Contracts (nexus-cluster)

- `NodeAddress` — Value object for multi-machine node addressing (`cluster/datacenter/application/node`).
- `ClusterTransport` interface — `send(NodeAddress $target, string $data): void`. For future TCP inter-node transport.
- `NodeDirectory` interface — Maps actor paths to `NodeAddress` for multi-machine routing.
- `NodeHashRing` — Consistent hash ring mapping actor names to `NodeAddress` instances.
```

**Step 5: Update Psalm plugin section (line ~350)**

Replace:
```
3. **NonSerializableClusterMessageRule** — `RemoteActorRef::tell()` messages need `#[MessageType]` attribute
```
With:
```
3. **NonSerializableRemoteMessageRule** — `WorkerActorRef::tell()` messages need `#[MessageType]` attribute
```

**Step 6: Update Integration Tests section (line ~371)**

Replace:
```
- Integration tests: `tests/Integration/{Fiber,Swoole,Step,Serialization,Cluster,Persistence}/`
```
With:
```
- Integration tests: `tests/Integration/{Fiber,Swoole,Step,Serialization,WorkerPool,Persistence}/`
```

**Step 7: Commit**

```bash
git add CLAUDE.md
git commit -m "docs(claude): update architecture section for worker-pool/cluster separation"
```

---

## Task 5: Supersede ADR 0005 and update ADR 0007

**Files:**
- Modify: `docs/adr/0005-multi-process-clustering.md`
- Modify: `docs/adr/0007-remote-ask-protocol.md`

**Step 1: Add superseded status to ADR 0005**

At the very top of `docs/adr/0005-multi-process-clustering.md`, replace:
```markdown
## Status
Accepted
```
With:
```markdown
## Status
Superseded by [ADR 0008](0008-worker-pool-cluster-separation.md)

> This ADR describes the original multi-process clustering design using `Process\Pool`
> and Unix socket IPC (`UnixSocketTransport`, `SwooleTableDirectory`). The architecture
> was replaced by a Swoole thread-based worker pool in ADR 0008. The content is preserved
> for historical context.
```

**Step 2: Update ADR 0007 class names**

In `docs/adr/0007-remote-ask-protocol.md`, make these find-and-replace changes:

| Old | New |
|-----|-----|
| `RemoteAskRequest` | `WorkerAskRequest` |
| `RemoteAskReply` | `WorkerAskReply` |
| `RemoteAskCancel` | `WorkerAskCancel` |
| `RemoteAskCancelled` | `WorkerAskCancelled` |
| `RemoteAskAck` | `WorkerAskAck` |
| `cluster remote ask` | `worker pool ask` |
| `RemoteActorRef` | `WorkerActorRef` |
| `cluster serializer/transport` | `worker pool transport` |
| `### 2. Control messages` section list item text: keep structure, just rename the five classes |  |

Also update the title line in `### 2. Control messages`:
Replace:
```markdown
- `RemoteAskRequest`
- `RemoteAskReply`
- `RemoteAskCancel`
- `RemoteAskCancelled`
- `RemoteAskAck`

All are transported as envelope payloads over the existing cluster serializer/transport path.
```
With:
```markdown
- `WorkerAskRequest`
- `WorkerAskReply`
- `WorkerAskCancel`
- `WorkerAskCancelled`
- `WorkerAskAck`

All are transported as envelope payloads via `WorkerTransport` (no serializer — objects pass directly).
```

**Step 3: Commit**

```bash
git add docs/adr/0005-multi-process-clustering.md docs/adr/0007-remote-ask-protocol.md
git commit -m "docs(adr): supersede ADR 0005, update ADR 0007 class names to WorkerAsk*"
```

---

## Task 6: Write ADR 0008 — Worker Pool / Cluster Separation

**File:** `docs/adr/0008-worker-pool-cluster-separation.md`

**Step 1: Create ADR 0008**

```markdown
# ADR 0008: Worker Pool / Cluster Separation

## Status
Accepted

## Context

The original `nexus-cluster` (see ADR 0005) used OS-process isolation via
`Swoole\Process\Pool`, Unix socket IPC, and `php_serialize` for all cross-worker
messages. This introduced ~50 µs of serialization overhead per message hop.

Swoole 6.0 introduced `SWOOLE_THREAD` mode with `Thread\Queue` and `Thread\Map`
primitives that allow PHP objects to be shared between threads without explicit
serialization. The internal copy is handled by Swoole's thread engine.

These are two fundamentally different scaling models:

- **Local thread-based**: Multiple OS threads in the same process. Objects pass via
  `Thread\Queue` copies. No network or serialization overhead.
- **Remote node-based** (future): Multiple machines connected via TCP. Requires a
  serialization wire format for cross-network message delivery.

The original `nexus-cluster` tried to serve both models in one package, creating
unnecessary coupling and preventing optimal implementations for either.

## Decision

Split into three packages with clear boundaries:

**`nexus-worker-pool`** — Pure PHP, local worker pool contracts and implementation.
- `WorkerNode`: per-worker coordinator, routes via consistent hash ring
- `WorkerTransport` / `WorkerDirectory`: envelope-based interfaces (no serializer)
- `WorkerActorRef`: cross-worker ref that sends `Envelope` objects directly
- `WorkerAsk*` protocol: request/reply/cancel/ack control flow (see ADR 0007)
- `InMemoryWorkerTransport` / `InMemoryWorkerDirectory`: test doubles
- Depends only on `nexus-core` and `nexus-runtime`

**`nexus-worker-pool-swoole`** — Swoole thread primitives.
- `ThreadQueueTransport`: `Thread\Queue`-backed transport with adaptive-poll coroutine
- `ThreadMapDirectory`: `Thread\Map`-backed shared actor directory
- `WorkerRunnable` + `WorkerPoolBootstrap` + `WorkerPoolApp`: thread pool bootstrap
- Depends on `nexus-worker-pool`, `nexus-core`, and `nexus-runtime-swoole`

**`nexus-cluster`** — Stripped to remote contracts only. No implementation.
- `NodeAddress`: multi-machine node identity (`cluster/datacenter/application/node`)
- `ClusterTransport`: TCP-level send/listen interface
- `NodeDirectory`: actor path → `NodeAddress` mapping
- `NodeHashRing`: consistent hash ring over `NodeAddress` list
- Depends only on `nexus-core`

`nexus-cluster-swoole` (the Swoole process-based implementation) is deleted entirely.
A future TCP cluster implementation will live in a new `nexus-cluster-swoole` package
that implements the `ClusterTransport` and `NodeDirectory` contracts.

## Consequences

### Positive
- No serialization overhead for same-machine workers. Objects cross thread boundaries
  via `Thread\Queue`'s internal copy mechanism.
- Clean architectural boundary: local scaling (worker pool) vs remote scaling (future
  cluster) have independent packages with independent interfaces.
- `nexus-worker-pool` has no Swoole dependency — usable with any PHP runtime that
  supports threads (future compatibility).
- `nexus-cluster` is a thin contracts package — easy to depend on without pulling in
  Swoole.

### Trade-offs
- The `WorkerAsk*` protocol (request/reply/cancel/ack) mirrors the old `RemoteAsk*`
  naming; they are equivalent protocols. When the TCP cluster is built, it will use
  a similar protocol under `ClusterAsk*` or equivalent naming.
- `WorkerActorRef` does not enforce `#[MessageType]` for actual serialization — the
  Psalm rule `NonSerializableRemoteMessageRule` is kept as a convention check for
  forward compatibility when messages may eventually cross a serialization boundary.
```

**Step 2: Force-add and commit** (docs/adr/ is tracked in git already)

```bash
git add docs/adr/0008-worker-pool-cluster-separation.md
git commit -m "docs(adr): add ADR 0008 — worker pool / cluster separation decision"
```

---

## Task 7: Rewrite website scaling docs

**Files:**
- Modify: `website/docs/scaling/overview.md`
- Modify: `website/docs/scaling/configuration.md`
- Modify: `website/docs/scaling/bootstrap.md`

**Step 1: Rewrite scaling/overview.md**

Replace the entire file with:

```markdown
# Scaling Overview

Nexus scales to multiple CPU cores on the same machine using a thread-based worker pool.
Each worker thread runs an independent `ActorSystem`. Actors are distributed across workers
via a consistent hash ring. Messages between workers are delivered as `Envelope` objects
directly through `Swoole\Thread\Queue` — no serialization step.

## Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│  WorkerPoolBootstrap (main thread)                             │
│  Thread\Map (shared directory)   Thread\Queue[0..N-1] (inboxes)│
└──────────────────┬──────────────────────────────────────────────┘
                   │ Thread\Pool spawns N threads
     ┌─────────────┼─────────────┐
     ▼             ▼             ▼
  Worker 0      Worker 1      Worker 2
  ActorSystem   ActorSystem   ActorSystem
  WorkerNode    WorkerNode    WorkerNode
```

### Key components

- **`WorkerNode`** — Coordinator for one worker. On `spawn()`, consults the hash ring to
  decide whether the actor lives locally or on another worker. Registers the result in the
  shared `WorkerDirectory`.
- **`ConsistentHashRing`** — Maps actor names to worker IDs via CRC32 with 150 virtual
  nodes per worker for uniform distribution.
- **`WorkerActorRef`** — Implements `ActorRef<T>`. For actors on other workers, `tell()`
  wraps the message in an `Envelope` and pushes it to the target worker's `Thread\Queue`.
  No serializer; `Thread\Queue` handles the internal copy.
- **`ThreadQueueTransport`** — One `Swoole\Thread\Queue` per worker as inbox. A
  coroutine-based receive loop with adaptive backoff polls the queue and delivers
  incoming envelopes to local actor mailboxes.
- **`ThreadMapDirectory`** — Shared `Swoole\Thread\Map` mapping actor path strings to
  worker IDs. All threads read and write the same map; `Thread\Map` handles synchronization.

## Message flow

### Local delivery (actor on same worker)
```
tell() → Envelope → LocalActorRef → mailbox → handler
```

### Cross-worker delivery
```
tell() → Envelope → WorkerActorRef
  → ThreadQueueTransport.send(targetWorker, envelope)
  → Thread\Queue[targetWorker].push(envelope)      (Thread\Queue copies object)
  → receive loop on target worker
  → LocalActorRef.enqueueEnvelope(envelope)
  → mailbox → handler
```

## Location transparency

`WorkerNode.spawn()` returns an `ActorRef<T>`. Whether the actor lives on this worker or
another, the caller uses the same interface:

```php
$ref = $node->spawn(Props::fromBehavior($behavior), 'orders');
$ref->tell(new PlaceOrder($items));  // identical regardless of which worker owns 'orders'
```

## Performance characteristics

- **Cross-worker throughput**: ~260K messages/sec per worker pair (no serialization step)
- **Cross-worker latency**: ~20 µs round-trip
- **Worker count**: set to the number of available CPU cores for CPU-bound workloads

## Future: multi-machine clustering

For distributing actors across multiple machines over TCP, see the `nexus-cluster` package.
It provides the `ClusterTransport`, `NodeDirectory`, and `NodeHashRing` contracts.
A TCP-based implementation will arrive in a future `nexus-cluster-swoole` package.
```

**Step 2: Rewrite scaling/configuration.md**

Replace the entire file with:

```markdown
# Configuration

Worker pool configuration is minimal — thread count is the only required parameter.

## WorkerPoolConfig

```php
use Monadial\Nexus\WorkerPool\WorkerPoolConfig;

$config = WorkerPoolConfig::withThreads(8);
```

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `workerCount` | `int` | yes | Number of worker threads. Use `swoole_cpu_num()` for CPU-bound workloads. |

`WorkerPoolConfig` is immutable and `readonly`.

## Actor placement

The consistent hash ring assigns actor names to workers deterministically:

```php
use Monadial\Nexus\WorkerPool\ConsistentHashRing;

$ring = new ConsistentHashRing(workerCount: 4);
$workerId = $ring->getWorker('orders');  // same result on every worker, every run
```

- Algorithm: CRC32 over the actor name, mapped to 150 virtual nodes per worker.
- Distribution: statistically uniform with 150 virtual nodes.
- Stability: adding workers changes assignment for ~1/N actors (consistent hashing guarantee).

## WorkerNode

Each worker thread has its own `WorkerNode`. You normally don't instantiate it directly —
`WorkerRunnable` creates it automatically. It is passed to your `WorkerStartHandler`:

```php
use Monadial\Nexus\WorkerPool\WorkerNode;
use Monadial\Nexus\WorkerPool\WorkerStartHandler;

final class MyApp extends WorkerPoolApp
{
    protected function configure(WorkerNode $node): void
    {
        // spawn actors — WorkerNode routes local vs remote automatically
        $node->spawn(Props::fromBehavior($ordersBehavior), 'orders');
        $node->spawn(Props::fromBehavior($paymentsBehavior), 'payments');
    }
}
```

`spawn()` returns an `ActorRef<T>`. If the hash ring assigns the name to another worker,
the ref is a `WorkerActorRef` that routes through `ThreadQueueTransport`. If it belongs
to this worker, it is a `LocalActorRef`.
```

**Step 3: Rewrite scaling/bootstrap.md**

Replace the entire file with:

```markdown
# Bootstrap

## WorkerPoolApp (recommended)

Extend `WorkerPoolApp`, override `configure()`, call `run()`:

```php
use Monadial\Nexus\WorkerPool\Swoole\WorkerPoolApp;
use Monadial\Nexus\WorkerPool\WorkerNode;
use Monadial\Nexus\WorkerPool\WorkerPoolConfig;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Actor\Behavior;

final class MyApp extends WorkerPoolApp
{
    protected function configure(WorkerNode $node): void
    {
        $node->spawn(
            Props::fromBehavior(
                Behavior::receive(static fn($ctx, $msg) => Behavior::same()),
            ),
            'orders',
        );
    }
}

MyApp::run(WorkerPoolConfig::withThreads(swoole_cpu_num()));
```

`configure()` is called once per worker thread with a fresh `WorkerNode`. Closures inside
`configure()` are safe — the class is re-instantiated in each thread, so no closure crosses
a thread boundary.

## WorkerPoolBootstrap (lower-level)

```php
use Monadial\Nexus\WorkerPool\Swoole\WorkerPoolBootstrap;
use Monadial\Nexus\WorkerPool\WorkerPoolConfig;

WorkerPoolBootstrap::create(WorkerPoolConfig::withThreads(4))
    ->withHandler(MyWorkerStartHandler::class)
    ->run();
```

`withHandler()` accepts a `class-string<WorkerStartHandler>`. The class is instantiated
fresh in each thread.

## WorkerStartHandler

Implement `WorkerStartHandler` directly when you need more control:

```php
use Monadial\Nexus\WorkerPool\WorkerStartHandler;
use Monadial\Nexus\WorkerPool\WorkerNode;

final class MyWorkerStartHandler implements WorkerStartHandler
{
    public function onWorkerStart(WorkerNode $node): void
    {
        $node->spawn(Props::fromBehavior($behavior), 'orders');
    }
}
```

## Looking up actors

After spawning, look up a ref by path from within a handler:

```php
$ref = $node->actorFor('/user/orders');  // null if not registered
```

## Example: distributed counter

```php
use Monadial\Nexus\WorkerPool\Swoole\WorkerPoolApp;
use Monadial\Nexus\WorkerPool\WorkerNode;
use Monadial\Nexus\WorkerPool\WorkerPoolConfig;

readonly class Increment {}
readonly class GetCount { public function __construct(public ActorRef $replyTo) {} }

final class CounterApp extends WorkerPoolApp
{
    protected function configure(WorkerNode $node): void
    {
        // Spawn 8 named counters — each lands on the worker its name hashes to
        for ($i = 0; $i < 8; $i++) {
            $node->spawn(
                Props::fromBehavior(
                    Behavior::withState(
                        0,
                        static function ($ctx, $msg, int $count) {
                            if ($msg instanceof Increment) {
                                return BehaviorWithState::next($count + 1);
                            }
                            if ($msg instanceof GetCount) {
                                $msg->replyTo->tell($count);
                            }
                            return BehaviorWithState::same();
                        },
                    ),
                ),
                "counter-{$i}",
            );
        }
    }
}

CounterApp::run(WorkerPoolConfig::withThreads(4));
```
```

**Step 4: Commit**

```bash
git add website/docs/scaling/overview.md \
        website/docs/scaling/configuration.md \
        website/docs/scaling/bootstrap.md
git commit -m "docs(website): rewrite scaling docs for thread-based worker pool"
```

---

## Task 8: Rewrite website packages/cluster.md, delete packages/cluster-swoole.md

**Files:**
- Modify: `website/docs/packages/cluster.md`
- Delete: `website/docs/packages/cluster-swoole.md`

**Step 1: Rewrite packages/cluster.md**

Replace the entire file with:

```markdown
# nexus-cluster

Remote contracts for future TCP-based multi-machine clustering.

This package contains **interfaces and value objects only** — no implementation.
A future `nexus-cluster-swoole` package will implement these interfaces using
TCP transport and a distributed directory.

## Installation

```bash
composer require nexus-actors/cluster
```

## Contracts

### NodeAddress

Identifies a node in the cluster hierarchy:

```php
use Monadial\Nexus\Cluster\NodeAddress;

$node = new NodeAddress(
    cluster: 'production',
    datacenter: 'eu-west-1',
    application: 'nexus-app',
    node: 'node-3',
);

echo $node->toString(); // production/eu-west-1/nexus-app/node-3
```

### ClusterTransport

```php
interface ClusterTransport
{
    public function send(NodeAddress $target, string $data): void;
    public function listen(callable $onMessage): void;
    public function close(): void;
}
```

TCP-level byte transport between nodes. The `$data` string is a serialized envelope.
Implementations provide the actual network transport.

### NodeDirectory

```php
interface NodeDirectory
{
    public function register(string $path, NodeAddress $node): void;
    public function lookup(string $path): ?NodeAddress;
}
```

Maps actor path strings to cluster node addresses for routing.

### NodeHashRing

```php
use Monadial\Nexus\Cluster\NodeHashRing;
use Monadial\Nexus\Cluster\NodeAddress;

$ring = new NodeHashRing([$nodeA, $nodeB, $nodeC]);
$target = $ring->getNode('orders');  // deterministic assignment
```

Same CRC32 algorithm as `WorkerPool\ConsistentHashRing`, but maps names to
`NodeAddress` instances instead of integer worker IDs.

## Relationship to nexus-worker-pool

For same-machine multi-core scaling, use `nexus-worker-pool` and
`nexus-worker-pool-swoole` — they use Swoole threads and need no serialization.

`nexus-cluster` is reserved for cross-machine scaling (different hosts, TCP transport).
See [Scaling Overview](../scaling/overview.md) for the thread-based worker pool.
```

**Step 2: Delete packages/cluster-swoole.md**

```bash
git rm website/docs/packages/cluster-swoole.md
```

**Step 3: Commit**

```bash
git add website/docs/packages/cluster.md
git commit -m "docs(website): rewrite cluster package page as contracts-only, delete cluster-swoole page"
```

---

## Task 9: Create website packages/worker-pool.md and packages/worker-pool-swoole.md

**Files:**
- Create: `website/docs/packages/worker-pool.md`
- Create: `website/docs/packages/worker-pool-swoole.md`

**Step 1: Create packages/worker-pool.md**

```markdown
# nexus-worker-pool

Core worker pool abstractions and implementations. Pure PHP — no Swoole dependency.

## Installation

```bash
composer require nexus-actors/worker-pool
```

## WorkerNode

The central coordinator for one worker thread. Handles actor spawn routing,
transport listening, and the worker ask protocol.

```php
use Monadial\Nexus\WorkerPool\WorkerNode;
use Monadial\Nexus\WorkerPool\ConsistentHashRing;
use Monadial\Nexus\WorkerPool\WorkerPoolConfig;
use Monadial\Nexus\WorkerPool\Directory\InMemoryWorkerDirectory;
use Monadial\Nexus\WorkerPool\Transport\InMemoryWorkerTransport;

$node = new WorkerNode(
    workerId: 0,
    system: $actorSystem,
    transport: $transport,
    ring: new ConsistentHashRing(workerCount: 4),
    directory: $directory,
);

$node->start();  // begin listening on transport

$ref = $node->spawn(Props::fromBehavior($behavior), 'orders');
$ref->tell(new PlaceOrder($items));
```

### Methods

| Method | Description |
|--------|-------------|
| `spawn(Props $props, string $name): ActorRef` | Spawn actor locally or return WorkerActorRef for remote worker |
| `actorFor(string $path): ?ActorRef` | Look up a registered actor by path |
| `start(): void` | Register the transport listener |
| `workerId(): int` | This worker's ID |
| `system(): ActorSystem` | The underlying ActorSystem |

## WorkerActorRef

Implements `ActorRef<T>` for actors on other workers. `tell()` wraps the message
in an `Envelope` and pushes it to the target worker's transport inbox.

No serializer is involved — the transport implementation handles object delivery
(e.g. `Thread\Queue` copies the object internally).

## ConsistentHashRing

```php
use Monadial\Nexus\WorkerPool\ConsistentHashRing;

$ring = new ConsistentHashRing(workerCount: 4);
$workerId = $ring->getWorker('orders');  // 0, 1, 2, or 3
```

CRC32-based hash ring with 150 virtual nodes per worker. Immutable and `readonly`.

## WorkerPoolConfig

```php
$config = WorkerPoolConfig::withThreads(8);
echo $config->workerCount; // 8
```

## WorkerTransport (interface)

```php
interface WorkerTransport
{
    public function send(int $targetWorker, Envelope $envelope): void;
    public function listen(callable $onEnvelope): void;
    public function close(): void;
}
```

### InMemoryWorkerTransport (test double)

```php
$transport = new InMemoryWorkerTransport();
$transport->send(1, $envelope);

$sent = $transport->getSentTo(1);   // list<Envelope> sent to worker 1
$all  = $transport->getSent();      // all sent entries with targetWorker

$transport->receive($envelope);     // simulate an incoming envelope
```

## WorkerDirectory (interface)

```php
interface WorkerDirectory
{
    public function register(string $path, int $workerId): void;
    public function lookup(string $path): ?int;
    public function has(string $path): bool;
}
```

### InMemoryWorkerDirectory (test double)

```php
$dir = new InMemoryWorkerDirectory();
$dir->register('/user/orders', 2);
$dir->lookup('/user/orders');  // 2
$dir->has('/user/orders');     // true
```

## WorkerStartHandler (interface)

```php
interface WorkerStartHandler
{
    public function onWorkerStart(WorkerNode $node): void;
}
```

Implement this interface to set up actors when a worker thread starts.
Pass the class name (string) to `WorkerPoolBootstrap::withHandler()`.
```

**Step 2: Create packages/worker-pool-swoole.md**

```markdown
# nexus-worker-pool-swoole

Swoole thread primitives for the worker pool: `Thread\Queue` transport,
`Thread\Map` directory, and `Thread\Pool` bootstrap.

Requires `ext-swoole >= 6.0` with `SWOOLE_THREAD` support.

## Installation

```bash
composer require nexus-actors/worker-pool-swoole
```

## WorkerPoolApp (high-level entry point)

The recommended way to boot a worker pool. Mirror of `NexusApp` for multi-worker setups.

```php
use Monadial\Nexus\WorkerPool\Swoole\WorkerPoolApp;
use Monadial\Nexus\WorkerPool\WorkerNode;
use Monadial\Nexus\WorkerPool\WorkerPoolConfig;

final class MyApp extends WorkerPoolApp
{
    protected function configure(WorkerNode $node): void
    {
        $node->spawn(Props::fromBehavior($ordersBehavior), 'orders');
        $node->spawn(Props::fromFactory(fn() => new PaymentActor()), 'payments');
    }
}

MyApp::run(WorkerPoolConfig::withThreads(swoole_cpu_num()));
```

`configure()` runs once per thread. Closures are safe — the class is re-instantiated in
each thread; nothing crosses a thread boundary.

## WorkerPoolBootstrap (lower-level)

```php
use Monadial\Nexus\WorkerPool\Swoole\WorkerPoolBootstrap;

WorkerPoolBootstrap::create(WorkerPoolConfig::withThreads(4))
    ->withHandler(MyWorkerStartHandler::class)
    ->run();
```

Accepts a `class-string<WorkerStartHandler>`. The class is instantiated fresh per thread.
`run()` blocks until the pool exits.

## ThreadQueueTransport

Thread-safe `WorkerTransport` backed by one `Swoole\Thread\Queue` per worker.

```php
$queues = [0 => new Queue(), 1 => new Queue(), 2 => new Queue()];
$transport = new ThreadQueueTransport($queues, workerId: 1);

$transport->send(0, $envelope);   // push to worker 0's queue
$transport->listen($handler);     // start adaptive-poll receive loop
$transport->close();              // stop the loop
```

### Adaptive poll backoff

The receive coroutine uses non-blocking `Queue::pop(0)` to stay coroutine-friendly
(blocking `pop()` would freeze the OS thread):

| Consecutive empty polls | Sleep |
|------------------------|-------|
| < 10 | `Coroutine::sleep(0)` — immediate yield |
| 10 – 99 | 100 µs |
| 100 – 999 | 1 ms |
| ≥ 1000 | 10 ms (idle steady state) |

When a message arrives, the counter resets to zero.

## ThreadMapDirectory

Thread-safe `WorkerDirectory` backed by a shared `Swoole\Thread\Map`.

```php
use Swoole\Thread\Map;

$map = new Map();  // shared across all workers
$dir = new ThreadMapDirectory($map);

$dir->register('/user/orders', 2);
$dir->lookup('/user/orders');  // 2
$dir->has('/user/orders');     // true
```

All workers share the same `Map` instance passed as a constructor argument.
`Thread\Map` handles synchronization internally.

## WorkerRunnable

Thread entrypoint implementing `Swoole\Thread\Runnable`. Used internally by
`WorkerPoolBootstrap` — you do not instantiate this directly.

On `run()`:
1. Atomically claims a worker ID via `Thread\Atomic`.
2. Creates `SwooleRuntime`, `ActorSystem`, `ThreadMapDirectory`, `ThreadQueueTransport`.
3. Builds `ConsistentHashRing` and `WorkerNode`.
4. Calls `$node->start()` (registers transport listener).
5. Instantiates and calls the `WorkerStartHandler`.
6. Calls `$system->run()` — blocks until shutdown.
```

**Step 3: Commit**

```bash
git add website/docs/packages/worker-pool.md \
        website/docs/packages/worker-pool-swoole.md
git commit -m "docs(website): add worker-pool and worker-pool-swoole package reference pages"
```

---

## Task 10: Update website intro, app, and psalm pages

**Files:**
- Modify: `website/docs/intro.md`
- Modify: `website/docs/packages/app.md`
- Modify: `website/docs/packages/psalm.md`

**Step 1: Update intro.md package table**

In `website/docs/intro.md`, find the package listing table or section that includes
`nexus-cluster-swoole` and apply these changes:
- Remove the row/entry for `nexus-cluster-swoole`
- Update the `nexus-cluster` description to: "Remote contracts for future TCP-based multi-machine clustering"
- Add entries for:
  - `nexus-worker-pool` — "Local thread-based worker pool with consistent-hash routing"
  - `nexus-worker-pool-swoole` — "Swoole thread primitives: Thread\\Queue transport, Thread\\Map directory, Thread\\Pool bootstrap"

**Step 2: Update packages/app.md cluster section**

Find the section comparing `NexusApp` to `ClusterBootstrap` (around lines 60-77). Replace
any mention of `ClusterBootstrap` with `WorkerPoolBootstrap` / `WorkerPoolApp`:

Replace (approximate):
```markdown
For multi-process scaling, use `ClusterBootstrap` instead, iterating `$app->actors()`.
```
With:
```markdown
For multi-worker scaling, use `WorkerPoolApp` or `WorkerPoolBootstrap` from
`nexus-worker-pool-swoole`. See [Scaling Bootstrap](../scaling/bootstrap.md).
```

**Step 3: Update packages/psalm.md rule 3**

Find the `NonSerializableClusterMessage` rule description. Replace:
```markdown
**NonSerializableClusterMessage** — Messages passed to `RemoteActorRef::tell()` must
carry a `#[MessageType]` attribute for cross-worker serialization registration.
```
With:
```markdown
**NonSerializableRemoteMessage** — Messages passed to `WorkerActorRef::tell()` must
carry a `#[MessageType]` attribute. This is a forward-compatibility check — the worker
pool itself does not serialize messages, but marking them ensures they are ready for
future TCP cluster transport.
```

Also update any code example that references `NonSerializableClusterMessage` to use the
new issue class name `NonSerializableRemoteMessage`.

**Step 4: Commit**

```bash
git add website/docs/intro.md \
        website/docs/packages/app.md \
        website/docs/packages/psalm.md
git commit -m "docs(website): update intro, app, and psalm pages for worker-pool separation"
```

---

## Task 11: Update website architecture, runtimes, roadmap, and sidebars

**Files:**
- Modify: `website/docs/architecture/design-philosophy.md`
- Modify: `website/docs/architecture/performance.md`
- Modify: `website/docs/runtimes/swoole.md`
- Modify: `website/docs/runtimes/overview.md`
- Modify: `website/docs/contributing/roadmap.md`
- Modify: `website/sidebars.js`

**Step 1: Update architecture/design-philosophy.md**

Find the Location Transparency section that lists three `ActorRef` implementations.
Replace the `RemoteActorRef` entry:
```markdown
- **RemoteActorRef** — Cross-worker via ClusterSerializer and Transport over Unix sockets
```
With:
```markdown
- **WorkerActorRef** — Cross-thread within a worker pool via `ThreadQueueTransport`. No serializer; objects pass via `Thread\Queue` internal copy.
```

**Step 2: Update architecture/performance.md**

Find the multi-process scaling benchmarks section. Update:
- The section header from "Multi-process scaling" to "Multi-worker scaling (Swoole threads)"
- Remove the serialization throughput benchmark entry (`1.18M serialize+deserialize cycles/sec`) — there is no serialization in the thread-based worker pool
- Update the Wire format / CompactClusterSerializer section — remove it entirely (not used in worker pool). If there is a general wire format section, replace it with a note: "The worker pool passes `Envelope` objects directly via `Thread\Queue`. No wire serialization format is needed for same-machine scaling."
- Keep cross-worker throughput (260K msgs/sec) and latency (20 µs) numbers — these apply to thread-based delivery too

**Step 3: Update runtimes/swoole.md clustering mention**

Find the section mentioning "Multi-process clustering" with Unix socket IPC. Replace:
```markdown
Multi-process clustering — The Swoole runtime is the foundation for Nexus cluster mode,
where multiple worker processes coordinate via Unix socket IPC and shared-memory directories.
```
With:
```markdown
Multi-worker scaling — The Swoole runtime powers the worker pool, where multiple worker
threads coordinate via `Thread\Queue` (one inbox per worker) and a shared `Thread\Map`
actor directory. See [Scaling Overview](../scaling/overview.md).
```

**Step 4: Update runtimes/overview.md**

Find the SwooleRuntime row/entry that mentions "multi-process scaling". Update the
description to reference "multi-worker scaling" and link to `../scaling/overview.md`.

**Step 5: Update contributing/roadmap.md**

Find the "Multi-process scaling" completed item. Update its description and item list:

Replace the completed cluster items:
```markdown
- ClusterBootstrap, ConsistentHashRing, RemoteActorRef, UnixSocketTransport, SwooleTableDirectory
```
With:
```markdown
- WorkerPoolApp, WorkerPoolBootstrap, WorkerNode, ConsistentHashRing, WorkerActorRef, ThreadQueueTransport, ThreadMapDirectory
```

Find the "Multi-server clustering" planned item. Update it to reflect the new contract-based approach:
```markdown
**Multi-machine clustering** (planned) — TCP-based clustering across multiple hosts.
`nexus-cluster` contracts (`ClusterTransport`, `NodeDirectory`, `NodeHashRing`, `NodeAddress`)
are defined. A future `nexus-cluster-swoole` will provide the TCP implementation.
```

**Step 6: Update sidebars.js**

Replace:
```js
        'packages/cluster',
        'packages/cluster-swoole',
```
With:
```js
        'packages/cluster',
        'packages/worker-pool',
        'packages/worker-pool-swoole',
```

**Step 7: Commit**

```bash
git add website/docs/architecture/design-philosophy.md \
        website/docs/architecture/performance.md \
        website/docs/runtimes/swoole.md \
        website/docs/runtimes/overview.md \
        website/docs/contributing/roadmap.md \
        website/sidebars.js
git commit -m "docs(website): update architecture, runtimes, roadmap, and sidebar navigation"
```

---

## Task 12: Run full verification

**Step 1: Run unit tests**

```bash
docker compose exec php vendor/bin/phpunit --testsuite=unit
```

Expected: all pass.

**Step 2: Run Psalm**

```bash
docker compose exec php vendor/bin/psalm
```

Expected: `No errors found!`

**Step 3: Run PHPCS**

```bash
docker compose exec php vendor/bin/phpcs
```

Expected: no violations.

**Step 4: Run integration tests**

```bash
docker compose exec php vendor/bin/phpunit --testsuite=integration-fiber
docker compose run --rm php-swoole vendor/bin/phpunit --testsuite=integration-workerPool
```

Expected: all pass.

**Step 5: Final commit if anything was adjusted**

If any files were adjusted during verification:
```bash
git add -A
git commit -m "chore: fix any issues found during cleanup verification"
```
