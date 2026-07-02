---
sidebar_position: 2
title: Deployment
related:
  - operations/overview
  - operations/observability
  - operations/performance-tuning
  - http/servers
---

# Deployment

This page covers the production configuration required before exposing a Nexus application to traffic: OPcache, health checks, graceful shutdown, reverse proxy setup, process supervision, and resource limits.

For platform-specific deployment guides, see:

- [Docker](deployment/docker.md)
- [systemd](deployment/systemd.md)
- [Kubernetes](deployment/kubernetes.md)

## Route caching

In development, `discover()` scans the handlers directory and parses attributes on every boot. Under load with frequent worker recycling, that work is wasted.

```php title="src/bootstrap.php"
use Psr\SimpleCache\CacheInterface;

$app->withRouteCache($psr16Cache, key: 'app-routes-' . APP_VERSION)
    ->discover(__DIR__ . '/src/Http/Handlers')
    ->compile();
```

- Include a version tag in the cache key and bump it on every deploy to invalidate.
- Any PSR-16 store works. In-memory (`ArrayAdapter`) is fine if you accept a per-worker rebuild on first boot; combined with OPcache, that is a few milliseconds.
- A shared store (Redis, APCu) skips the rebuild entirely — useful at large worker counts.

## OPcache

A production `php.ini` for Nexus:

```ini title="docker/opcache.ini"
opcache.enable=1
opcache.enable_cli=1
opcache.memory_consumption=256
opcache.max_accelerated_files=20000
opcache.validate_timestamps=0

opcache.preload=/app/preload.php
opcache.preload_user=www-data

opcache.jit_buffer_size=64M
opcache.jit=tracing
```

For preloading, compile the framework hot path at boot:

```php title="preload.php"
<?php
require __DIR__ . '/vendor/autoload.php';

foreach ([
    \Monadial\Nexus\Core\Actor\ActorSystem::class,
    \Monadial\Nexus\Http\App\CompiledHttpApp::class,
    \Monadial\Nexus\Http\Routing\Dispatcher::class,
    \Monadial\Nexus\Http\Middleware\RouterMiddleware::class,
] as $class) {
    opcache_compile_file((new \ReflectionClass($class))->getFileName());
}
```

JIT shows real gains on the dispatch hot loop and PSR-7 message construction. Measure with `wrk` before and after — numbers vary by application.

## Health checks

Two endpoints, two purposes:

```php title="src/bootstrap.php"
// Liveness — is the process up?
$app->get('/health/live', static fn () => Response::ok());

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

Kubernetes uses both: liveness restarts the pod on failure; readiness removes it from the service endpoint list without restarting.

## Graceful shutdown

Match `shutdownTimeout` to your orchestrator's grace period, minus a buffer for the OS-level kill:

```yaml title="kubernetes/deployment.yaml"
spec:
  terminationGracePeriodSeconds: 15
  containers:
    - name: api
      lifecycle:
        preStop:
          exec:
            command: ["/bin/sh", "-c", "sleep 5"]
```

```php title="src/bootstrap.php"
SwooleThreadConfig::bind('0.0.0.0', 8080)
    ->shutdownTimeout(Duration::seconds(8));  // 15s grace - 5s preStop - 2s safety
```

The five-second `preStop` sleep gives the load balancer time to remove the pod from rotation before the container starts draining.

## Reverse proxy

Run Nexus behind a TLS-terminating proxy (nginx, Caddy, Envoy, an ALB). Two reasons:

1. **TLS performance** — Dedicated proxies are better at session resumption, OCSP stapling, and certificate rotation.
2. **HTTP/2 and HTTP/3** — Most operators run h2/h3 at the edge and HTTP/1.1 internally to Swoole.

Trust headers from the proxy via middleware:

```php title="src/Http/Middleware/TrustProxyMiddleware.php"
<?php

declare(strict_types=1);

use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use Psr\Http\Server\{MiddlewareInterface, RequestHandlerInterface};

final class TrustProxyMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly array $trustedProxies = ['10.0.0.0/8'],
    ) {}

    public function process(
        ServerRequestInterface $req,
        RequestHandlerInterface $next,
    ): ResponseInterface {
        $remote = $req->getServerParams()['REMOTE_ADDR'] ?? '';

        if (!$this->isTrusted($remote)) {
            return $next->handle($req);
        }

        $req = $req
            ->withAttribute('clientIp', $req->getHeaderLine('X-Forwarded-For') ?: $remote)
            ->withAttribute('scheme', $req->getHeaderLine('X-Forwarded-Proto') ?: 'http');

        return $next->handle($req);
    }

    private function isTrusted(string $ip): bool { /* CIDR check */ }
}
```

:::caution Never trust X-Forwarded-* without a whitelist
A public-facing Swoole instance must restrict `X-Forwarded-*` trust to known proxy CIDRs. Accepting these headers from untrusted sources allows clients to spoof their IP and scheme.
:::

## Process supervision

Use a supervisor that does not conflict with Swoole's signal handling:

| Supervisor | Configuration |
|---|---|
| **Kubernetes** | Set `terminationGracePeriodSeconds` + `preStop` sleep. |
| **systemd** | `Type=simple`, `KillSignal=SIGTERM`, `TimeoutStopSec=15`. |
| **s6 / runit** | Send `SIGTERM` on stop; use a long `down` timeout. |
| **Docker** | `--restart unless-stopped` for single-container; does not handle gradual rollout. |

Set `installSignalHandlers(true)` (the default) — Swoole catches `SIGTERM`/`SIGINT` and starts the drain automatically.

## Resource limits

```php title="src/bootstrap.php"
SwooleThreadConfig::bind('0.0.0.0', 8080)
    ->threads(8)
    ->maxConn(10_000)
    ->maxRequest(20_000);
```

For worker mode, replace `threads()` with `workers()`. The `maxConn` and `maxRequest` knobs are identical in both modes.

Each accepted connection consumes one file descriptor. For a 10k-connection deploy, raise the container `ulimit`:

```bash
ulimit -n 65536
```

## Pre-flight checklist

Before promoting a build to production:

- [ ] `ErrorMode::Production` (not `Development`)
- [ ] Route cache key bumped or shared store cleared
- [ ] `maxRequest()` set to bound memory growth
- [ ] `shutdownTimeout` matches orchestrator grace period
- [ ] Health check returns 503 when actors are unhealthy
- [ ] Access log middleware registered globally
- [ ] MDC populated with `host`, `service`, `requestId`
- [ ] OPcache enabled, `validate_timestamps=0`
- [ ] Reverse proxy CIDRs whitelisted; `X-Forwarded-*` trusted only from those sources
- [ ] Container `ulimit -n` raised

## See also

- [Docker deployment](deployment/docker.md) — Dockerfile targets and container configuration
- [systemd deployment](deployment/systemd.md) — service unit and socket activation
- [Kubernetes deployment](deployment/kubernetes.md) — manifests, probes, and rolling updates
- [Observability](observability.md) — logging, MDC, and metrics
- [Performance tuning](performance-tuning.md) — OPcache, JIT, Swoole server settings, and kernel parameters
