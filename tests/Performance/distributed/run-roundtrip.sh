#!/usr/bin/env bash
#
# Distributed round-trip demo: 16 containers = 16 cluster nodes over real cross-container
# TCP. Node 1 (worker1) drives `ask` round trips to node 16 (worker16), which replies —
# every completed round trip is a request + reply over real TCP with MessagePack. Traces +
# metrics export to a Grafana LGTM all-in-one. Unlike run.sh, this LEAVES Grafana running
# after the workers finish so you can explore the data.
#
# Usage:  ./tests/Performance/distributed/run-roundtrip.sh [durationSeconds] [payloadBytes]
# Then:   open http://localhost:3000  ->  Explore -> Tempo -> { service.name = "nexus-cluster-roundtrip" }
# Stop:   ./tests/Performance/distributed/run-roundtrip.sh --down
# Exit:   0 = all 16 nodes PASSed, 1 otherwise.
set -euo pipefail

cd "$(dirname "$0")"

COMPOSE="docker compose -f compose.roundtrip.yaml -p nexus-roundtrip"

if [ "${1:-}" = "--down" ]; then
  echo "tearing down the round-trip stack (Grafana + workers)…"
  $COMPOSE down -v --remove-orphans
  exit 0
fi

export DURATION="${1:-240}"
export PAYLOAD="${2:-1024}"
export ASK_CONCURRENCY="${ASK_CONCURRENCY:-64}"
export TARGET_RPS="${TARGET_RPS:-2000}"
export OTEL_SAMPLE="${OTEL_SAMPLE:-0.1}"
# Shared start epoch: containers share the host clock, so all 16 nodes open and close
# their load windows together (boot + convergence must fit in the margin).
export START_MARGIN="${START_MARGIN:-40}"
export START_AT="$(($(date +%s) + START_MARGIN))"

NODES=16
LOG=/tmp/roundtrip-last.log

echo "building image…"
$COMPOSE build --quiet >/dev/null

echo "starting Grafana LGTM (loki/grafana/tempo/mimir + otel collector)…"
$COMPOSE up -d lgtm >/dev/null 2>&1

printf "waiting for Grafana to become healthy"
status=starting
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

WORKERS="$(for n in $(seq 1 "$NODES"); do printf 'worker%s ' "$n"; done)"

echo "running mesh: ${NODES} nodes, ${DURATION}s window, ${PAYLOAD} B payload, ask concurrency=${ASK_CONCURRENCY}, target ${TARGET_RPS} rt/s, trace sample=${OTEL_SAMPLE}"
# shellcheck disable=SC2086
$COMPOSE up -d --force-recreate $WORKERS >/dev/null 2>&1
# shellcheck disable=SC2086
$COMPOSE logs -f --no-log-prefix $WORKERS 2>&1 | tee "$LOG" &
LOGGER=$!

# Bounded wait: margin + duration + generous convergence/teardown grace.
HARD_DEADLINE=$(( $(date +%s) + START_MARGIN + DURATION + 180 ))
TIMED_OUT=0
while true; do
  RUNNING="$(docker ps -q --filter "name=nexus-roundtrip-worker" | wc -l | tr -d ' ')"
  [ "$RUNNING" = "0" ] && break
  if [ "$(date +%s)" -gt "$HARD_DEADLINE" ]; then
    echo "TIMEOUT: workers still running past the hard deadline (log: $LOG)"
    TIMED_OUT=1
    break
  fi
  sleep 5
done

sleep 1
kill $LOGGER 2>/dev/null || true

# Per-container exit-code aggregation.
FLEET_RC=0
if [ "$TIMED_OUT" = "0" ]; then
  for n in $(seq 1 "$NODES"); do
    # -a: include exited containers (plain `ps -q` lists only running ones).
    cid="$($COMPOSE ps -aq "worker$n" 2>/dev/null || true)"
    [ -n "$cid" ] || { FLEET_RC=1; continue; }
    rc="$(docker inspect -f '{{.State.ExitCode}}' "$cid" 2>/dev/null || echo 1)"
    [ "$rc" = "0" ] || FLEET_RC=1
  done
else
  FLEET_RC=1
fi

echo
echo "── per-node verdicts ──"
grep -E "^\[n[0-9]+\] (PASS|FAIL)" "$LOG" | sort -V || true

echo
echo "── driver throughput ──"
grep -E "^\[n1\] SUMMARY" "$LOG" || echo "(no driver summary — check the log)"

echo
echo "── aggregate ──"
PASS_COUNT="$(grep -cE "^\[n[0-9]+\] PASS" "$LOG" || true)"
printf 'nodes passed:    %s/%s\n' "$PASS_COUNT" "$NODES"
echo "full log:        $LOG"

cat <<EOF

── observability ──
Grafana: http://localhost:3000 — Tempo traces under service nexus-cluster-roundtrip
  Traces:  Explore → Tempo → TraceQL:  { resource.service.name = "nexus-cluster-roundtrip" }
           Slice per node via the  node.id  resource attribute (n1 … n16).
Grafana is still running. Tear down when done:  ./run-roundtrip.sh --down
EOF

if [ "$PASS_COUNT" = "$NODES" ] && [ "$FLEET_RC" = "0" ]; then
  echo "RESULT: PASS (${PASS_COUNT}/${NODES})"
  exit 0
fi

echo "RESULT: FAIL"
exit 1
