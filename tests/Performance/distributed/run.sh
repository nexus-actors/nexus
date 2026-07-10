#!/usr/bin/env bash
#
# Distributed mesh soak driver: 4 containers x 4 threads = 16 cluster nodes over
# real cross-container TCP. Brings the fleet up, streams logs continuously to
# /tmp/mesh-soak-last.log, samples aggregate CPU, waits (bounded) for the fleet
# to finish, aggregates verdicts, tears down.
#
# Usage: ./tests/Performance/distributed/run.sh [durationSeconds] [payloadBytes]
# Exit:  0 = every node PASSed, 1 otherwise.
#
# This is the authoritative verdict and runs WITHOUT the observability overlay
# (compose.observability.yaml) — full tracing/metrics instrumentation can induce
# observer-effect instability on this harness's single-core-per-node reactor. The
# Grafana overlay (run-observability.sh) is inspection-only; never read its dashboards
# as a health signal for this soak.
set -euo pipefail

cd "$(dirname "$0")"

export DURATION="${1:-300}"
export PAYLOAD="${2:-1024}"
# Shared start epoch: containers share the host clock, so all 16 nodes open and
# close their load windows together (boot + convergence must fit in the margin).
export START_MARGIN="${START_MARGIN:-120}"
export START_AT="$(($(date +%s) + START_MARGIN))"

COMPOSE="docker compose -f compose.yaml -p nexus-mesh-soak"
LOG=/tmp/mesh-soak-last.log
TMP="$(mktemp -d)"
trap '$COMPOSE kill >/dev/null 2>&1 || true; $COMPOSE down -v --remove-orphans >/dev/null 2>&1 || true; rm -rf "$TMP"' EXIT

echo "mesh soak: ${WORKERS:-4} containers x ${THREADS:-4} threads, ${DURATION}s, ${PAYLOAD} B payload"
$COMPOSE build --quiet >/dev/null
$COMPOSE up -d --force-recreate >/dev/null 2>&1

# Stream logs continuously so a hang or crash is still diagnosable afterwards.
$COMPOSE logs -f --no-log-prefix > "$LOG" 2>&1 &
LOGGER=$!

CIDS="$($COMPOSE ps -q)"

# Sample summed container CPU while the fleet runs.
(
  while true; do
    docker stats --no-stream --format '{{.CPUPerc}}' $CIDS 2>/dev/null \
      | tr -d '%' | awk '{s+=$1} END {if (NR>0) printf "%.0f\n", s}' >> "$TMP/cpu.log"
    sleep 10
  done
) &
SAMPLER=$!

# Bounded wait: margin + duration + generous convergence/teardown grace.
HARD_DEADLINE=$(( $(date +%s) + START_MARGIN + DURATION + 180 ))
FLEET_RC=0
TIMED_OUT=0

while true; do
  RUNNING="$(docker ps -q --filter "name=nexus-mesh-soak-" | wc -l | tr -d ' ')"
  [ "$RUNNING" = "0" ] && break

  if [ "$(date +%s)" -gt "$HARD_DEADLINE" ]; then
    echo "TIMEOUT: fleet still running past the hard deadline — killing (log: $LOG)"
    TIMED_OUT=1
    FLEET_RC=1
    break
  fi

  sleep 5
done

kill $SAMPLER 2>/dev/null || true

if [ "$TIMED_OUT" = "0" ]; then
  for cid in $CIDS; do
    rc="$(docker inspect -f '{{.State.ExitCode}}' "$cid" 2>/dev/null || echo 1)"
    [ "$rc" = "0" ] || FLEET_RC=1
  done
fi

sleep 1
kill $LOGGER 2>/dev/null || true

echo
echo "── per-node verdicts ──"
grep -E "^\[w[0-9]+t[0-9]+\] (PASS|FAIL)" "$LOG" | sort || true

echo
echo "── aggregate ──"
PASS_COUNT="$(grep -cE "^\[w[0-9]+t[0-9]+\] PASS" "$LOG" || true)"
NODES=$(( ${WORKERS:-4} * ${THREADS:-4} ))
TOTAL_RECV="$(grep -E "^\[w[0-9]+t[0-9]+\] (PASS|FAIL)" "$LOG" | grep -oE "received=[0-9,]+" | tr -dc '0-9\n' | awk '{s+=$1} END {print s}')"
printf 'nodes passed:    %s/%s\n' "$PASS_COUNT" "$NODES"
printf 'total received:  %s messages in %ss  (~%s msg/s aggregate)\n' \
  "$(printf "%'d" "${TOTAL_RECV:-0}")" "$DURATION" "$(printf "%'d" $(( ${TOTAL_RECV:-0} / DURATION )))"
if [ -s "$TMP/cpu.log" ]; then
  awk '{s+=$1; if($1>m)m=$1; n++} END {printf "fleet CPU:       mean %.0f%%, peak %.0f%% (host total 1600%%)\n", s/n, m}' "$TMP/cpu.log"
fi
echo "full log:        $LOG"

if [ "$PASS_COUNT" = "$NODES" ] && [ "$FLEET_RC" = "0" ]; then
  echo "RESULT: PASS (${PASS_COUNT}/${NODES})"
  exit 0
fi

echo "RESULT: FAIL"
exit 1
