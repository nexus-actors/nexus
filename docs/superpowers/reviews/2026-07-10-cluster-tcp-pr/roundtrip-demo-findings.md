# 16-Container Cluster Round-Trip Demo — Build Report

## Deliverables (all under `tests/Performance/distributed/`)

1. **`roundtrip_node.php`** — one ClusterNode per container (no `Swoole\Thread`). Env: `NODE_ID`, `NODES` (16), `BASE_PORT` (7361), `DURATION` (240), `START_AT`, `ASK_CONCURRENCY` (64), `TARGET_RPS` (2000, added — see findings), `PAYLOAD` (1024). Node 1 drives `ask(Ping)->await()` round trips against node 16's `rt-echo` actor (`/user/rt-echo`), MessagePack-serialized `Ping`/`Pong` fixtures via `TypeRegistry::registerFromAttribute`. OTEL service `nexus-cluster-roundtrip`, per-node `node.id` resource attr.
2. **`compose.roundtrip.yaml`** — self-contained: 16 `worker1..16` services + `grafana/otel-lgtm` with healthcheck/provisioning volumes; OTEL env on every worker; `memory_limit=512M` command; own bridge network.
3. **`run-roundtrip.sh`** (executable) — lgtm-first startup, START_AT sync (margin 40 s), bounded deadline wait, per-container exit-code + PASS aggregation, leaves lgtm running, exits 0 only on 16/16.

## Smoke run (60 s, 512 B payload)

- **Convergence:** all 16 nodes Up within ~1 s of boot (unix 1783950354 → 1783950355).
- **Result:** `RESULT: PASS (16/16)`, script exit 0. askFailures=0, suspicion growth 0, down 0.
- **Throughput:** 96,932 round trips, avg **1,615 roundtrips/s** (paced at TARGET_RPS=2000; unpaced ceiling measured ~10,000/s).
- **RTT:** p50 **2,080 µs**, p99 **75,355 µs** (with OTEL; without OTEL p99 was ~5 ms — span-batch export pauses dominate the tail).
- **Traces:** Tempo confirmed complete distributed chains. Example traceID **`6149cfeb93153a28eaeb96b25a45480b`** containing: `n1: cluster.ask` (Producer) → `n16: cluster.receive` (Consumer) → `n16: process Ping` (Consumer, echo actor) → `n1: cluster.receive` (Consumer, the Pong reply). Two more verified: `4b00b378e3e1f4e582e69b1ff73248eb`, `328cf623c46fc092dee7abec32db7e5f`.
- Smoke stack fully torn down afterwards, including lgtm.

## Debugging journey (5 failed smoke iterations before PASS)

1. **Driver OOM** (128 MB default): unbounded RTT sample list + 64 in-flight asks + OTLP buffers → fixed with a 16 384-slot ring buffer + `memory_limit=512M`.
2. **OTLP export 100% broken under Swoole hooks:** this Swoole build lacks native curl, so `SWOOLE_HOOK_ALL` routes curl through the userland shim, which rejects `CURLOPT_SHARE` — symfony/http-client (the only PSR-18 client in vendor) always sets it. EVERY in-run export threw; ~1,200 multi-line stack traces/node/min to stderr stalled reactors mesh-wide. Fixed by `Coroutine::set(['hook_flags' => SWOOLE_HOOK_ALL & ~SWOOLE_HOOK_CURL & ~SWOOLE_HOOK_NATIVE_CURL])` inside the event loop (exports then use real blocking curl, a few ms per batch, and succeed live) + `OTEL_PHP_LOG_DESTINATION=none` as defense.
3. **Continuous false-suspicion storm (~1.7 suspicions/s mesh-wide, throughput- and OTEL-independent) — the big one.** Root cause: membership gossip (which doubles as heartbeats) fans out to only `min(3, peers)` random peers per tick. On a DATA-IDLE link, heartbeat inter-arrival is exponential with ~2.5 s mean → P(gap > maxNoHeartbeat 10 s) ≈ 2 % per gap × 240 directed links ≈ the measured rate. The 4×4 soak never sees this because its flood traffic feeds the liveness path (`PeerLivenessObserved → recordLiveness → PhiAccrualDetector::heartbeat`) on every link continuously. **Fixed in-harness with a 1 tell/s/peer keepalive from every node** (15 msg/s/node); suspicion growth dropped to exactly 0.
4. **Ask-flood saturation:** unpaced 64-coroutine ask loop drove ~10 k rt/s, pinning n16's reactor (p99 138 ms). Added `TARGET_RPS` pacing (default 2000).
5. **Verdict race at window end:** graceful `Leave` frames from marginally-earlier-exiting peers counted as `NodeDown` "under load". Fixed by snapshotting judgment counters 2 s before the shared deadline (all nodes provably alive).
6. **Script bug:** `docker compose ps -q` omits exited containers → exit-code aggregation always failed; fixed with `ps -aq`.

## Product findings worth upstreaming

- **Idle-mesh false suspicion (finding, not fixed in product):** a 16-node cluster with NO data traffic continuously false-suspects at default settings (gossip fan-out 3, maxNoHeartbeat 10 s). Real deployments with quiet periods would flap. Options: per-peer heartbeat fan-out to all peers, SWIM-style probing, or scaling fan-out with cluster size.
- **OTLP export is fully broken under `SWOOLE_HOOK_ALL`** when Swoole lacks native curl (symfony/http-client + `CURLOPT_SHARE`). The existing mesh obs demo only got data because the final `shutdown()` flush runs outside coroutine context. Consider excluding curl hooks in `SwooleRuntime` docs or shipping a stream-based PSR-18 fallback for `nexus-observability-otel`.

## Verification checklist

- `php -l` clean (Docker).
- `docker compose -f compose.roundtrip.yaml config -q` valid.
- Full smoke: 16/16 PASS, exit 0, nonzero throughput, cross-node traces in Tempo.
- Smoke stack torn down including lgtm.
