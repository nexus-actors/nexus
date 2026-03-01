# Cleanup and Documentation Consolidation Design

**Date:** 2026-03-01
**Branch:** feat/worker-pool-cluster-separation (or follow-up branch)

---

## Goal

After the worker-pool / cluster separation refactor, clean up residual naming issues,
dead placeholder files, and update all documentation to reflect the new architecture.

---

## Section 1: Code Cleanup

### 1a — Psalm plugin rename

The `NonSerializableClusterMessageRule` hook and `NonSerializableClusterMessage` issue
class carry stale "Cluster" names. The rule validates `WorkerActorRef::tell()` arguments
and will eventually validate a future `RemoteActorRef` too — "Remote" is the correct
generic name.

Files to rename:
- `packages/nexus-psalm/src/Hook/NonSerializableClusterMessageRule.php`
  → `NonSerializableRemoteMessageRule.php` (rename file and class)
- `packages/nexus-psalm/src/Issue/NonSerializableClusterMessage.php`
  → `NonSerializableRemoteMessage.php` (rename file and class)
- `packages/nexus-psalm/src/Plugin.php` — update `use` statement and class reference in `$hooks` array

### 1b — Remove stale `.gitkeep` files

`.gitkeep` files in directories that now contain real PHP files are dead weight.

Remove `.gitkeep` from every directory that has at least one `.php` file.
Keep the `.gitkeep` in `packages/nexus-core/src/Runtime` — intentionally empty,
reserved for future runtime interfaces.

### 1c — Remove per-package `.gitignore` files

All per-package `.gitignore` files inside `packages/*/` are redundant — the root
`.gitignore` covers the entire repo. Remove all of them.
The root-level `.gitignore` is kept unchanged.

---

## Section 2: CLAUDE.md

The `### Clustering` architecture subsection describes seven classes that no longer exist.
Replace it with three subsections:

**Worker Pool (`nexus-worker-pool`)**
Document: `WorkerNode`, `ConsistentHashRing`, `WorkerTransport`, `WorkerDirectory`,
`WorkerActorRef`, `WorkerPoolConfig`, `WorkerStartHandler`, `InMemoryWorkerTransport`,
`InMemoryWorkerDirectory`.

**Worker Pool Swoole (`nexus-worker-pool-swoole`)**
Document: `WorkerPoolApp`, `WorkerPoolBootstrap`, `WorkerRunnable`,
`ThreadQueueTransport`, `ThreadMapDirectory`, `DefaultWorkerStartHandler`.

**Cluster — Remote Contracts (`nexus-cluster`)**
Document: `NodeAddress`, `ClusterTransport`, `NodeDirectory`, `NodeHashRing`.
Framed as future TCP remote-node contracts only — no implementation yet.

Also update:
- Package dependency graph: add `nexus-worker-pool` and `nexus-worker-pool-swoole`
- Psalm plugin section: `NonSerializableClusterMessageRule` → `NonSerializableRemoteMessageRule`

---

## Section 3: ADRs

### ADR 0005 — Status: Superseded

Add superseded status and a note at the top:

> This ADR describes the original multi-process clustering design using
> `Process\Pool` and Unix socket IPC (`UnixSocketTransport`, `SwooleTableDirectory`).
> Superseded by ADR 0008.

No other content changes — preserve the historical record.

### ADR 0007 — Update class names only

Status remains `Accepted`. The protocol rules are unchanged. Update:
- `RemoteAsk*` → `WorkerAsk*` throughout
- `RemoteActorRef` → `WorkerActorRef`
- "cluster remote ask" → "worker pool ask"

### ADR 0008 (new) — Worker Pool / Cluster Separation

**Status:** Accepted

**Context:**
The original `nexus-cluster` used OS-process-level isolation (`Process\Pool`) with Unix
socket IPC and `php_serialize` for all cross-worker messages (~50 µs per hop). Swoole 6
introduced `SWOOLE_THREAD` mode with `Thread\Queue` and `Thread\Map`, which pass PHP
objects between threads without serialization. These are fundamentally different scaling
models with different implementation requirements.

**Decision:**
Split into three packages:
1. `nexus-worker-pool` — thread-based local worker pool, envelope transport, no serializer.
   `WorkerNode` routes via consistent hash ring. `WorkerTransport` sends `Envelope` objects directly.
2. `nexus-worker-pool-swoole` — Swoole thread primitives: `Thread\Queue` per-worker inbox,
   shared `Thread\Map` directory, `Thread\Pool` bootstrap, adaptive-poll coroutine loop.
3. `nexus-cluster` stripped to remote contracts only: `NodeAddress`, `ClusterTransport`,
   `NodeDirectory`, `NodeHashRing`. A future TCP-based cluster implementation will live in
   a new `nexus-cluster-swoole` (or similar) package implementing these interfaces.

`nexus-cluster-swoole` (the old package) is deleted entirely.

**Consequences:**
- No serialization overhead for same-machine workers — objects cross thread boundaries
  via `Thread\Queue` internal copy.
- Clean separation: local scaling (worker pool) vs remote scaling (future cluster) have
  independent packages and interfaces.
- `WorkerActorRef` and `WorkerNode` share the same ask protocol structure as the old
  `RemoteActorRef`/`ClusterNode`, renamed to `WorkerAsk*`.
- `nexus-cluster` is now a contracts-only package with no Swoole dependency.

---

## Section 4: Website Docs

### Delete
- `website/docs/packages/cluster-swoole.md` — nexus-cluster-swoole no longer exists

### Create
- `website/docs/packages/worker-pool.md` — nexus-worker-pool API reference
- `website/docs/packages/worker-pool-swoole.md` — nexus-worker-pool-swoole reference

### Rewrite (old cluster content → new worker-pool content)
- `website/docs/scaling/overview.md` — thread-based architecture, no serialization, Thread\Queue/Map
- `website/docs/scaling/configuration.md` — `WorkerPoolConfig::withThreads(N)` replacing `ClusterConfig`
- `website/docs/scaling/bootstrap.md` — `WorkerPoolApp` / `WorkerPoolBootstrap` replacing `ClusterBootstrap`
- `website/docs/packages/cluster.md` — new role: contracts-only, forward-looking TCP remote description

### Targeted updates (find-replace + context)
- `website/docs/intro.md` — package table: remove nexus-cluster-swoole, add worker-pool packages
- `website/docs/packages/app.md` — replace ClusterBootstrap with WorkerPoolApp/WorkerPoolBootstrap
- `website/docs/packages/psalm.md` — NonSerializableClusterMessage → NonSerializableRemoteMessage
- `website/docs/architecture/design-philosophy.md` — RemoteActorRef → WorkerActorRef
- `website/docs/architecture/performance.md` — update cross-worker benchmark descriptions (no serialization)
- `website/docs/runtimes/swoole.md` — Unix socket IPC → thread-based worker pool
- `website/docs/runtimes/overview.md` — minor phrasing update
- `website/docs/contributing/roadmap.md` — update cluster roadmap to new separation
- `website/sidebars.js` — remove cluster-swoole, add worker-pool and worker-pool-swoole
