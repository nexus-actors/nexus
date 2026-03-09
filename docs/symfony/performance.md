# Performance guide

## Benchmark results

Benchmarks run on the symfony-demo application with `1 worker × 20 kernels` (`APP_RUNTIME_OPTIONS={"workers":1,"kernel_pool_size":20,"kernel_pool_max_pending":200}`), using `wrk` for load generation.

| Scenario | Req/s | p50 | p95 | p99 |
|---|---|---|---|---|
| Pure PHP (no I/O) | ~88 000 | 0.2 ms | 0.5 ms | 1.1 ms |
| SELECT 10 rows (MySQL, pooled) | ~54 000 | 1.2 ms | 2.4 ms | 4.0 ms |
| INSERT 1 row (tuned MySQL) | ~49 000 | 1.4 ms | 2.8 ms | 4.8 ms |

**Test setup:**
- Single Docker container, single Swoole worker.
- MySQL 8.0 running in the same Docker network.
- `DB_POOL_SIZE=32` (Swoole connection pool, 32 connections).
- Redis cache enabled for catalog/inventory reads.
- MySQL tuned with the flags below.

### What each scenario measures

**Pure PHP (no I/O):** The `/` endpoint returns the actor system name. This measures the overhead of the Swoole HTTP layer, the kernel pool dispatch, and the full Symfony request cycle. No database, no cache.

**SELECT 10 rows:** The `/catalog` endpoint fetches products and stock levels. Products come from Redis cache (warm); stock levels trigger a SELECT on MySQL via a Doctrine pooled connection.

**INSERT 1 row:** The `/orders` endpoint creates an order row. Every request is a Doctrine INSERT with an auto-commit transaction.

## MySQL tuning

The docker-compose.yml in the symfony-demo uses the following MySQL flags:

```yaml
command: >
    --innodb-flush-log-at-trx-commit=2
    --innodb-flush-method=O_DIRECT
    --innodb-buffer-pool-size=512M
    --innodb-log-file-size=256M
    --innodb-io-capacity=2000
    --innodb-io-capacity-max=4000
    --sync-binlog=0
    --skip-log-bin
```

| Flag | Default | Tuned | Effect |
|---|---|---|---|
| `innodb_flush_log_at_trx_commit` | `1` (fsync per commit) | `2` (flush per second) | Eliminates per-commit fsync; up to 10× write throughput, small risk of 1 second of data loss on crash |
| `sync_binlog` | `1` | `0` | Disables binary log sync; further reduces write latency |
| `skip-log-bin` | off | on | Disables binary log entirely; removes all binlog overhead |
| `innodb_buffer_pool_size` | 128 MB | 512 MB | More data in memory, fewer disk reads |
| `innodb_log_file_size` | 48 MB | 256 MB | Larger redo log reduces checkpoint frequency |
| `innodb_flush_method` | `fsync` | `O_DIRECT` | Bypasses OS page cache for InnoDB I/O |

**Production note:** `innodb_flush_log_at_trx_commit=2` and `skip-log-bin` trade durability for throughput. For financial or audit-critical data, revert to the MySQL defaults and expect lower write throughput.

## Connection pooling

Each Swoole worker must not use PHP-FPM-style per-request PDO connections — connection setup latency would dominate at high concurrency. The symfony-demo uses a `SwoolePoolMiddleware` Doctrine middleware that borrows a pooled PDO connection per coroutine and returns it when the coroutine ends.

Pool sizing: `DB_POOL_SIZE` should be set to `kernel_pool_size × workers`. With 1 worker and 20 kernels, 20–32 connections are sufficient. Over-provisioning connections wastes MySQL resources; under-provisioning stalls coroutines waiting for a free connection.

## Choosing workers × kernel_pool_size

Total parallelism = `workers × kernel_pool_size`.

Start with:

```
workers = number of physical CPU cores
kernel_pool_size = p99_latency_ms / (1000 / target_rps_per_worker)
```

Practical starting points:

| Hardware | Workload | Recommended config |
|---|---|---|
| 4-core VM, I/O-bound | REST API + DB | `workers=4, kernel_pool_size=8` |
| 8-core server, I/O-bound | REST API + DB + cache | `workers=8, kernel_pool_size=16` |
| 4-core VM, compute-bound | PDF generation, image resize | `workers=4, kernel_pool_size=2` |
| 8-core server, mixed | API + background jobs | `workers=8, kernel_pool_size=8` |

### Memory budget

Each kernel holds a full Symfony container. Estimate container memory with:

```bash
php -d memory_limit=-1 public/index.php &
# Send one request, then inspect
```

Or use Blackfire/SPX. A typical Symfony 7 app with Doctrine, Messenger, and a few bundles uses 25–60 MB per kernel. With `workers=4, kernel_pool_size=8`, budget 32 kernels × ~40 MB = ~1.3 GB for the kernel pool alone, plus OS overhead.

## max_pending sizing

`kernel_pool_max_pending` acts as a circuit breaker: when the pool is saturated and the pending queue is full, the server returns 503 immediately rather than letting latency spiral.

Recommended formula:

```
max_pending = kernel_pool_size × burst_factor
```

For typical web APIs, `burst_factor = 2` is a conservative starting point. Monitor the 503 rate in production; if it is non-zero during normal load, increase `kernel_pool_size` or `max_pending`.

## Benchmarking methodology

Use `wrk` or `bombardier` for HTTP benchmarking. Warm the server before measuring:

```bash
# Warm-up
wrk -t4 -c100 -d10s http://localhost:8080/catalog

# Benchmark
wrk -t4 -c200 -d30s --latency http://localhost:8080/catalog
```

Key parameters:
- `-c` (connections) should be ≥ `workers × kernel_pool_size` to saturate the pool.
- `-t` (threads) should match the wrk client's CPU count.
- `-d` at least 30 seconds for stable p99 measurements.

Always benchmark with `APP_DEBUG=0` and a warmed OPcache. Debug mode disables the OPcache and adds significant overhead.

## Graceful shutdown

On `SIGTERM`, `GracefulShutdownHandler` calls `$actorSystem->shutdown($timeout)`. The actor system processes all in-flight messages up to `shutdown_timeout` seconds before stopping. Configure this timeout to be longer than the expected maximum request duration:

```yaml
nexus:
    shutdown_timeout: 30  # seconds
```

In Kubernetes, set `terminationGracePeriodSeconds` to `shutdown_timeout + 5` to allow the OS to deliver `SIGTERM` and wait for the process to exit cleanly before sending `SIGKILL`.
