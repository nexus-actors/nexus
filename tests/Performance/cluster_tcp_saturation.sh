#!/usr/bin/env bash
#
# Cluster-TCP saturation sweep.
#
# Launches K parallel two-node cluster workers inside the php-swoole container —
# each worker pins roughly one Swoole reactor (≈ one core) — and reports the
# AGGREGATE message throughput and peak container CPU as K grows. This is the
# "max out the machine" measurement: on an N-core host, throughput should scale
# with K until the cores saturate.
#
# Every worker is an independent two-node mesh over 127.0.0.1 (real
# SwooleMeshTransport), so K workers = 2K cluster nodes total.
#
# Usage:  ./tests/Performance/cluster_tcp_saturation.sh [msgsPerWorker] [payloadBytes]
# Run from the repo root (needs docker compose + the php-swoole service).
set -euo pipefail

MSGS="${1:-100000}"
PAYLOAD="${2:-1024}"
SWEEP=(1 2 4 8 12 16)

CID="$(docker compose ps -q php-swoole)"
if [ -z "$CID" ]; then
  echo "php-swoole container not running. Start it with: make up" >&2
  exit 1
fi

CORES="$(docker compose exec -T php-swoole nproc | tr -d '\r')"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

echo "cluster-tcp saturation sweep — ${MSGS} msgs/worker, ${PAYLOAD} B payload, host sees ${CORES} cores"
printf '%4s | %6s | %14s | %10s | %9s\n' "K" "nodes" "aggregate msg/s" "agg MB/s" "peak CPU%"
printf -- '-----+--------+----------------+------------+----------\n'

for K in "${SWEEP[@]}"; do
  # Launch K workers in the background, each writing its RESULT line to a file.
  pids=()
  for ((k = 0; k < K; k++)); do
    ( docker compose exec -T php-swoole php tests/Performance/bin/cluster_tcp_bench_worker.php "$MSGS" "$PAYLOAD" \
        2>/dev/null | grep '^RESULT' > "$TMP/w_${K}_${k}.out" ) &
    pids+=("$!")
  done

  # Sample container CPU while the workers run; keep the peak.
  peak_cpu=0
  for _ in $(seq 1 40); do
    # still running?
    if ! kill -0 "${pids[0]}" 2>/dev/null; then break; fi
    cpu="$(docker stats --no-stream --format '{{.CPUPerc}}' "$CID" 2>/dev/null | tr -d '%\r' || echo 0)"
    cpu_int="${cpu%.*}"
    if [ -n "$cpu_int" ] && [ "$cpu_int" -gt "$peak_cpu" ] 2>/dev/null; then peak_cpu="$cpu_int"; fi
  done

  for pid in "${pids[@]}"; do wait "$pid" || true; done

  # Aggregate the per-worker results.
  agg_msgps=0
  agg_mbps=0
  for f in "$TMP"/w_${K}_*.out; do
    [ -f "$f" ] || continue
    m="$(sed -n 's/.*msgps=\([0-9]*\).*/\1/p' "$f")"
    b="$(sed -n 's/.*mbps=\([0-9.]*\).*/\1/p' "$f")"
    agg_msgps=$((agg_msgps + ${m:-0}))
    agg_mbps="$(awk -v a="$agg_mbps" -v b="${b:-0}" 'BEGIN{printf "%.1f", a+b}')"
  done

  printf '%4s | %6s | %14s | %10s | %8s%%\n' "$K" "$((K * 2))" "$agg_msgps" "$agg_mbps" "$peak_cpu"
done
