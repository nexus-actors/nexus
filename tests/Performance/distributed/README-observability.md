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

- **http://localhost:3000** → *Dashboards* → **“Nexus Cluster TCP — Traces & Metrics”**
- *Explore → Tempo* → TraceQL: `{ resource.service.name = "nexus-cluster-mesh" }`
- *Explore → Prometheus* → any metric starting `nexus_cluster_` or `traces_spanmetrics_`

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

- **Cluster tracing** — a live Tempo trace list, plus span throughput and p95 latency
  per operation (`cluster.send`, `cluster.ask`, `cluster.handshake`) from Tempo's
  span-metrics.
- **Message throughput** — aggregate tells/s and bytes/s, local short-circuit rate.
- **Ask & health** — ask round-trip p50/p95/p99, asks pending/resolved/timed-out/rejected,
  handshake rejections, frames sent.

**Per-node breakdown caveat:** the “by node” panels rely on the collector promoting the
`node.id` resource attribute to a metric label. Not every LGTM build does this by
default — if those panels look empty, the aggregate panels and the per-`nexus.message.type`
and per-`span_name` breakdowns still work, and per-node identity is always present on
traces and on the `target_info` series.

> This is a **demo/inspection** configuration, not the pass/fail soak. For the soak
> verdict harness use `run.sh` (no observability overhead).
