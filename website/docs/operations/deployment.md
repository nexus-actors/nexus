---
sidebar_position: 12
title: Production
---

# Production

A checklist of things to do before turning the firehose on.

## Route Caching

In development, `discover()` scans the handlers directory and parses
attributes on every boot. That's fine for a few hundred routes; under
load with frequent worker recycling, it's wasted work.

```php
use Psr\SimpleCache\CacheInterface;

$app->withRouteCache($psr16Cache, key: 'app-routes-' . APP_VERSION)
    ->discover(__DIR__ . '/src/Http/Handlers')
    ->compile();
```

- **Cache key includes a version tag.** Bump it on deploy to invalidate.
- **Any PSR-16 store works.** In-memory (`Symfony\Component\Cache\Psr16Cache`
  over `ArrayAdapter`) is fine if you accept that the cache is rebuilt
  per worker on first boot — combined with OPcache, that's a few
  milliseconds.
- **Shared cache (Redis, APCu) skips the rebuild entirely.** Useful at
  large worker counts.

## OPcache

A typical `php.ini` for Nexus production:

```ini
; Enable
opcache.enable=1
opcache.enable_cli=1
opcache.memory_consumption=256
opcache.max_accelerated_files=20000
opcache.validate_timestamps=0     ; never restat in prod

; Preloading — load framework + handler classes at boot
opcache.preload=/app/preload.php
opcache.preload_user=www-data

; JIT — measurable wins for hot dispatch code paths
opcache.jit_buffer_size=64M
opcache.jit=tracing
```

For preloading, generate `preload.php` from your application class list:

```php title="preload.php"
<?php
require __DIR__ . '/vendor/autoload.php';

foreach ([
    \Monadial\Nexus\Core\Actor\ActorSystem::class,
    \Monadial\Nexus\Http\App\CompiledHttpApp::class,
    \Monadial\Nexus\Http\Routing\Dispatcher::class,
    \Monadial\Nexus\Http\Middleware\RouterMiddleware::class,
    \Monadial\Nexus\Http\Ws\WsApplication::class,
    // … your handler classes
] as $class) {
    opcache_compile_file((new ReflectionClass($class))->getFileName());
}
```

JIT shows real gains on the dispatch hot loop and PSR-7 message
construction. Measure with [wrk](https://github.com/wg/wrk) or
[k6](https://k6.io/) before and after; numbers vary by application.

## Health Checks

Two endpoints, two purposes:

```php
// Liveness — is the process up?
$app->get('/health/live', static fn() => Response::ok());

// Readiness — can it actually serve traffic?
$app->get('/health/ready', static function () use ($system, $deps) {
    if (!$system->isHealthy()) {
        return Response::serviceUnavailable();
    }

    foreach ($deps as $name => $check) {
        if (!$check()) {
            return JsonResponse::ok(['error' => "{$name} unhealthy"])->withStatus(503);
        }
    }

    return JsonResponse::ok(['status' => 'ok']);
});
```

Kubernetes uses both: liveness restarts the pod on failure; readiness
removes it from the service endpoint list without restarting.

## Graceful Shutdown

Match the runner's `shutdownTimeout` to your orchestrator's grace
period, minus a small buffer for the OS-level kill:

```yaml
# kubernetes/deployment.yaml
spec:
  terminationGracePeriodSeconds: 15
  containers:
    - name: api
      lifecycle:
        preStop:
          exec:
            command: ["/bin/sh", "-c", "sleep 5"]   # let LB drain first
```

```php
SwooleThreadConfig::bind('0.0.0.0', 8080)
    ->shutdownTimeout(Duration::seconds(8));   // 15s grace - 5s preStop - 2s safety
```

The five-second `preStop` sleep is critical when sitting behind a load
balancer — gives the LB time to remove the pod from its rotation before
the container starts draining.

## Reverse Proxy / Load Balancer

Run Nexus behind a TLS-terminating proxy (nginx, Caddy, Envoy, an ALB).
Two reasons:

1. **TLS performance.** Swoole's TLS is fine; dedicated proxies are
   better at session resumption, OCSP stapling, and certificate
   rotation.
2. **HTTP/2 and HTTP/3.** Most operators run h2/h3 at the edge and h1
   internally to Swoole.

Trust headers from the proxy via a middleware:

```php
final class TrustProxyMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly array $trustedProxies = ['10.0.0.0/8']) {}

    public function process(ServerRequestInterface $req, RequestHandlerInterface $next): ResponseInterface
    {
        $remote = $req->getServerParams()['REMOTE_ADDR'] ?? '';

        if (!$this->isTrusted($remote)) {
            return $next->handle($req);
        }

        // Rebind to the real client identity from X-Forwarded-* headers.
        $req = $req
            ->withAttribute('clientIp', $req->getHeaderLine('X-Forwarded-For') ?: $remote)
            ->withAttribute('scheme', $req->getHeaderLine('X-Forwarded-Proto') ?: 'http');

        return $next->handle($req);
    }

    private function isTrusted(string $ip): bool { /* CIDR check */ }
}
```

Never trust `X-Forwarded-*` from an untrusted source — a public-facing
Swoole instance must whitelist the proxy CIDRs.

## Process Supervision

Use a supervisor that won't conflict with Swoole's signal handling:

| Supervisor | Notes |
|---|---|
| **Kubernetes** | The default. Set `terminationGracePeriodSeconds` + `preStop`. |
| **systemd** | `Type=notify` if you wire `sd_notify(READY=1)`; otherwise `Type=simple`. Use `KillSignal=SIGTERM` and `TimeoutStopSec=15`. |
| **s6 / runit** | Send `SIGTERM` on stop; use a long `down` timeout. |
| **Docker (`docker run --restart`)** | OK for single-container; doesn't handle gradual rollout. |

In every case, set `installSignalHandlers(true)` (the default) — Swoole
catches `SIGTERM`/`SIGINT` and starts the drain.

## Resource Limits

Three knobs to set explicitly:

```php
SwooleWorkerConfig::bind('0.0.0.0', 8080)
    ->workers(8)                        // cap on parallelism per container
    ->maxConn(10_000)                   // protect against fd exhaustion
    ->maxRequest(20_000);               // recycle workers to bound memory growth
```

For thread mode, `threads()` replaces `workers()`. The other knobs are
identical.

### Memory

Each worker (process or thread) holds:

- The compiled application (immutable, shared across requests).
- The `ActorSystem` and its actor instances.
- PSR-11 container singletons.
- OPcache region (process-shared in worker mode).

The actor system itself is lightweight (~100KB cold). Your container
sizing should be driven by the actors and DI graph, not by the framework.

### File Descriptors

Each accepted connection consumes one fd. For a 10k-connection deploy:

```bash
# Container ulimit
ulimit -n 65536
```

Plus the corresponding kernel sysctl on the host:

```bash
sysctl -w fs.file-max=2000000
sysctl -w fs.nr_open=2000000
```

## Monitoring

Expose Prometheus-style metrics from a dedicated handler:

```php
$app->get('/metrics', static function () use ($registry) {
    return new Response(
        body: $registry->render(),     // text/plain Prometheus exposition format
        headers: ['Content-Type' => 'text/plain; version=0.0.4'],
    );
});
```

Or push to your APM provider from a global middleware. The key counters
to record:

- `http_requests_total{method, path, status}` — request rate
- `http_request_duration_seconds{method, path}` — latency histogram
- `http_in_flight_requests{worker}` — concurrency
- `actor_mailbox_size{actor}` — backpressure indicator
- `actor_dead_letters_total{actor}` — unrouted messages

For logs, ship via `Thread\Queue` to stdout and let your sidecar
(Fluent Bit, Vector, Promtail) handle structured log routing.

## Configuration Per Environment

A common shape — one bootstrap script reads environment variables:

```php
$config = SwooleThreadConfig::bind(
    $_ENV['LISTEN_HOST'] ?? '0.0.0.0',
    (int) ($_ENV['LISTEN_PORT'] ?? 8080),
);

if (isset($_ENV['WORKER_THREADS'])) {
    $config = $config->threads((int) $_ENV['WORKER_THREADS']);
}

if ($_ENV['ENV'] === 'production') {
    $config = $config
        ->maxRequest(20_000)
        ->shutdownTimeout(Duration::seconds(12));
}

SwooleThreadServer::run($config, $factory);
```

Keep the bootstrap script in version control — environment-specific
deltas live in env vars or a config file, not in code.

## Pre-Flight Checklist

Before promoting a build:

- [ ] `ErrorMode::Production`, not `Development`
- [ ] Route cache key bumped (or shared store cleared)
- [ ] `maxRequest()` set (bounded memory growth)
- [ ] `shutdownTimeout` matches orchestrator grace period
- [ ] Health check returns 503 when actors are unhealthy
- [ ] Access log middleware registered globally
- [ ] MDC populated with `host`, `service`, `requestId`
- [ ] Async logger via `Thread\Queue` (thread mode) or actor-backed
      sink (worker mode)
- [ ] `CallerInfoProcessor::onlyFor(Level::Debug, Level::Error)`
      (not always-on)
- [ ] OPcache enabled, `validate_timestamps=0`
- [ ] Reverse proxy CIDRs whitelisted; `X-Forwarded-*` only trusted
      from those sources
- [ ] `ulimit -n` raised on the container

That's the boring checklist. Once it passes, you have a Nexus HTTP server
that's safe to put on the public internet.
