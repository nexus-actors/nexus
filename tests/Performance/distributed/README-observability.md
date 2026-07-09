# Distributed mesh — Grafana LGTM observability demo

Runs the 4×4 distributed mesh (16 cluster nodes over real cross-container TCP) with
OpenTelemetry **metrics and traces** exported to a [Grafana LGTM](https://github.com/grafana/docker-otel-lgtm)
all-in-one (Loki · Grafana · Tempo · Mimir/Prometheus + an OTel Collector). The
`cluster.send` / `cluster.ask` / `cluster.handshake` spans and every `nexus.cluster.*`
metric land in Grafana, with a pre-provisioned dashboard.

## Run it

```bash
./tests/Performance/distributed/run-observability.sh          # ~180s window (default)
./tests/Performance/distributed/run-observability.sh 300      # longer window
```

The script builds the image, starts Grafana LGTM, runs the mesh pointed at it, and then
**leaves Grafana running** so you can explore. Open:

- **http://localhost:3000** → *Dashboards*:
  - **“Nexus — Cluster Overview (all subsystems)”** — the full picture: actor system,
    cluster TCP messaging, ask & membership, serialization, runtime/Swoole, persistence,
    worker pool, and distributed tracing in one board.
  - **“Nexus Cluster TCP — Traces & Metrics”** — the trace-focused cluster board.
- *Explore → Tempo* → TraceQL: `{ resource.service.name = "nexus-cluster-mesh" }` — traces
  are chained (`cluster.send` → remote `cluster.receive`).
- *Explore → Prometheus* → any metric starting `nexus_cluster_`, `nexus_actor_system_`, or `traces_spanmetrics_`
- *Explore → Loki* → `{service_name="nexus-cluster-mesh"}` — app/actor logs, correlated with traces.

Tear the stack down when finished:

```bash
./tests/Performance/distributed/run-observability.sh --down
```

## Tuning (env vars)

| Var | Default | Meaning |
|-----|---------|---------|
| `SEND_BATCH` | `8` | Tells per batch before yielding. Low = gentle, readable demo; raise toward 500 for a throughput soak. |
| `OTEL_SAMPLE` | `0.05` | Trace sample ratio. The mesh emits one span per message, so traces are sampled; **metrics are always fully aggregated**. Raise toward `1.0` only with a low `SEND_BATCH`. |
| `PAYLOAD` | `1024` | Message payload bytes. |
| `DURATION` | `180` | Load-window seconds (first script arg). |

## How the wiring works

- `compose.observability.yaml` overlays the base `compose.yaml`: it adds the `lgtm`
  service and injects `OTEL_EXPORTER_OTLP_ENDPOINT=http://lgtm:4318` (+ service name,
  sampler, `SEND_BATCH`) into every worker.
- `thread_mesh_node.php` builds a per-node OTel provider via `ObservabilityFactory`
  when `OTEL_EXPORTER_OTLP_ENDPOINT` is set (else no-op), tags each node with
  `node.id` / `worker.id` / `thread.id` resource attributes, and passes it into
  `ClusterNode::boot()`. It force-flushes every 10 s for a live time-series and calls
  `shutdown()` before the thread exits so the final batch is not lost.
- The dashboard JSON is mounted into LGTM's Grafana provisioning directory and loads
  automatically. If your LGTM version provisions from a different path, import it
  manually: *Dashboards → New → Import →* upload
  `grafana/dashboards/nexus-cluster-tcp.json`.

## What you'll see

The **overview** dashboard has a row per Nexus subsystem:

| Row | Source | Live in this demo? |
|-----|--------|--------------------|
| Actor system | `nexus.actor_system.*` (wired via `ActorSystemMetrics`) | ✅ |
| Cluster TCP — messaging / ask / membership | `nexus.cluster.*` | ✅ |
| Distributed tracing | Tempo span-metrics + trace list | ✅ |
| Serialization | `nexus.serialization.*` | only when a `TracingMessageSerializer` is wired |
| Runtime / Swoole | `swoole.*` | only when `SwooleAdminMetrics` is wired (needs a `Swoole\Server`) |
| Persistence | `nexus.persistence.*` | only in apps using persistence |
| Worker pool | `nexus.worker_pool.*` | only in apps using the worker pool |

The cluster mesh emits the Actor + Cluster + Tracing rows; the remaining rows are part of
the same board so it doubles as a **reusable full-stack Nexus overview** — they light up
automatically in a deployment that runs those subsystems with observability enabled.

The **cluster-tcp** dashboard is the trace-focused view: a live Tempo trace list plus span
throughput and p95 latency per operation (`cluster.send`, `cluster.ask`,
`cluster.handshake`), and the cluster message/ask/handshake metrics.

### Full span chains + internal actors

Observability is wired into the `ActorSystem`, so every actor message becomes a
`process {Type}` span and feeds `nexus.actor.messages.processed` /
`nexus.actor.message.processing.duration`. That means:

- **Distributed traces chain end to end:** `cluster.send` (sender) → `cluster.receive`
  (receiver) → `process Ping` (the receiving actor) — one connected trace across the
  network boundary.
- **The whole system is traced**, not just app messages — you'll see `process GossipReceived`,
  `process HeartbeatTick`, `process PeerLivenessObserved`, `process HandshakeReceived`, etc.,
  from the internal membership actors.

> **Observer-effect note.** Tracing *every* message (including the membership hot-path)
> costs real CPU, and a mesh node is single-core. Under a heavy flood this can slow the
> failure detector enough that a node reports a transient un-healed view — the telemetry
> still flows; it's the cost of full instrumentation. Keep `SEND_BATCH` modest for a
> stable demo, and use `run.sh` (no observability) for the clean pass/fail soak.

**Per-node breakdown caveat:** the “by node” panels rely on the collector promoting the
`node.id` resource attribute to a metric label. Not every LGTM build does this by
default — if those panels look empty, the aggregate panels and the per-`nexus.message.type`
and per-`span_name` breakdowns still work, and per-node identity is always present on
traces and on the `target_info` series.

> This is a **demo/inspection** configuration, not the pass/fail soak. For the soak
> verdict harness use `run.sh` (no observability overhead).
