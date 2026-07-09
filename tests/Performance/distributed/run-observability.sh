#!/usr/bin/env bash
#
# Observability demo for the 4x4 distributed mesh: 16 cluster nodes over real TCP,
# exporting OpenTelemetry metrics + traces to a Grafana LGTM all-in-one. Unlike run.sh,
# this LEAVES Grafana running after the mesh finishes so you can explore the data.
#
# Usage:  ./tests/Performance/distributed/run-observability.sh [durationSeconds]
# Then:   open http://localhost:3000  ->  Dashboards -> "Nexus Cluster TCP — Traces & Metrics"
# Stop:   ./tests/Performance/distributed/run-observability.sh --down
set -euo pipefail

cd "$(dirname "$0")"

COMPOSE="docker compose -f compose.yaml -f compose.observability.yaml -p nexus-mesh-obs"

if [ "${1:-}" = "--down" ]; then
  echo "tearing down the observability stack (Grafana + mesh)…"
  $COMPOSE down -v --remove-orphans
  exit 0
fi

# Demo-friendly defaults: gentle send rate + higher trace sampling so traces are readable,
# and a shorter window than the soak. Override via env.
export DURATION="${1:-180}"
export PAYLOAD="${PAYLOAD:-1024}"
export SEND_BATCH="${SEND_BATCH:-8}"
export OTEL_SAMPLE="${OTEL_SAMPLE:-0.05}"
export START_MARGIN="${START_MARGIN:-90}"
export START_AT="$(($(date +%s) + START_MARGIN))"

LOG=/tmp/mesh-obs-last.log

echo "building images…"
$COMPOSE build --quiet >/dev/null

echo "starting Grafana LGTM (loki/grafana/tempo/mimir + otel collector)…"
$COMPOSE up -d lgtm >/dev/null 2>&1

printf "waiting for Grafana to become healthy"
for _ in $(seq 1 60); do
  status="$(docker inspect -f '{{.State.Health.Status}}' "$($COMPOSE ps -q lgtm)" 2>/dev/null || echo starting)"
  [ "$status" = "healthy" ] && break
  printf '.'; sleep 2
done
echo " ${status:-unknown}"

if [ "${status:-}" != "healthy" ]; then
  echo "Grafana did not become healthy; check: $COMPOSE logs lgtm"
  exit 1
fi

echo "running mesh: 4 containers x ${THREADS:-4} threads, ${DURATION}s, SEND_BATCH=${SEND_BATCH}, trace sample=${OTEL_SAMPLE}"
$COMPOSE up -d --force-recreate worker1 worker2 worker3 worker4 >/dev/null 2>&1
$COMPOSE logs -f --no-log-prefix worker1 worker2 worker3 worker4 > "$LOG" 2>&1 &
LOGGER=$!

HARD_DEADLINE=$(( $(date +%s) + START_MARGIN + DURATION + 180 ))
while true; do
  RUNNING="$(docker ps -q --filter "name=nexus-mesh-obs-worker" | wc -l | tr -d ' ')"
  [ "$RUNNING" = "0" ] && break
  [ "$(date +%s)" -gt "$HARD_DEADLINE" ] && { echo "TIMEOUT: workers still running (log: $LOG)"; break; }
  sleep 5
done

kill $LOGGER 2>/dev/null || true

echo
echo "── per-node verdicts ──"
grep -E "^\[w[0-9]+t[0-9]+\] (PASS|FAIL)" "$LOG" | sort || true

cat <<EOF

── observability ──
Grafana is still running:  http://localhost:3000   (anonymous admin)
  Dashboard:  Dashboards → "Nexus Cluster TCP — Traces & Metrics"
  Traces:     Explore → Tempo → TraceQL:  { resource.service.name = "nexus-cluster-mesh" }
  Metrics:    Explore → Prometheus → metric starts with  nexus_cluster_

Full mesh log: $LOG
Tear down when done:  ./run-observability.sh --down
EOF
