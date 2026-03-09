# Deployment

This guide covers deploying a nexus-symfony application to production: prerequisites, Docker setup, environment configuration, worker sizing, reverse proxy configuration, process management, Kubernetes, health checks, graceful shutdown, logging, and static asset handling.

---

## Prerequisites checklist

nexus-symfony requires a specific PHP build configuration and Swoole extension version. Verify all prerequisites before deploying.

### PHP: ZTS build required

The Swoole thread mode (`SWOOLE_THREAD`) requires a ZTS (Zend Thread Safety) PHP binary. A standard NTS (non-thread-safe) build, which is the default in most Linux distributions and Docker images, will not work.

Verify:

```bash
php -i | grep 'Thread Safety'
```

The output must contain:

```
Thread Safety => enabled
```

If the output shows `Thread Safety => disabled`, the PHP binary is NTS and must be replaced with a ZTS build.

### Swoole: thread support required

Swoole must be compiled with `--enable-swoole-thread`. This is not the default in most Swoole distributions.

Verify:

```bash
php --ri swoole | grep -i thread
```

Expected output:

```
Thread => enabled
```

Verify the Swoole version (6.0 or later required):

```bash
php --ri swoole | grep 'swoole support'
```

### Extension checklist

```bash
# Verify all required extensions are loaded
php -m | grep -E 'swoole|pdo|opcache'
```

| Extension | Required | Purpose |
|-----------|----------|---------|
| `swoole` | Yes (ZTS + thread) | Coroutine HTTP server and thread pool |
| `opcache` | Yes (production) | Bytecode caching, essential for kernel boot performance |
| `pdo` + driver | Conditional | If the application uses Doctrine or direct PDO |
| `redis` or `igbinary` | Conditional | If using Redis cache or sessions |

**Xdebug must not be present in production.** Xdebug disables JIT and adds significant overhead to every function call. Verify:

```bash
php -m | grep xdebug
# Must produce no output
```

---

## Docker setup

### Dockerfile

The following Dockerfile builds a production image with ZTS PHP 8.5 and Swoole with thread support. It uses a multi-stage build: the `builder` stage installs Composer dependencies; the `runtime` stage contains only production files.

```dockerfile
# syntax=docker/dockerfile:1.7

# ──────────────────────────────────────────────────────────────────────────────
# Stage 1: Composer dependency installation
# ──────────────────────────────────────────────────────────────────────────────
FROM php:8.5-zts-cli-alpine AS builder

# Install system packages needed for Composer and PHP extensions
RUN apk add --no-cache \
    bash \
    git \
    unzip \
    libzip-dev \
    icu-dev

RUN docker-php-ext-install zip intl opcache

# Install Swoole with thread support
# --enable-swoole-thread requires ZTS PHP — the php:8.5-zts-cli-alpine base provides this
RUN pecl install swoole && docker-php-ext-enable swoole

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Copy dependency manifests first for layer caching
COPY composer.json composer.lock ./

# Install production dependencies only (no dev)
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-scripts \
    --optimize-autoloader \
    --prefer-dist

# Copy application source
COPY . .

# Run post-install scripts (e.g., cache:warmup) after all files are present
RUN composer run-script post-install-cmd --no-interaction || true

# ──────────────────────────────────────────────────────────────────────────────
# Stage 2: Production runtime image
# ──────────────────────────────────────────────────────────────────────────────
FROM php:8.5-zts-cli-alpine AS runtime

RUN apk add --no-cache \
    libzip \
    icu-libs

RUN docker-php-ext-install opcache

RUN pecl install swoole && docker-php-ext-enable swoole

# OPcache configuration for production
RUN { \
    echo '[opcache]'; \
    echo 'opcache.enable=1'; \
    echo 'opcache.memory_consumption=256'; \
    echo 'opcache.interned_strings_buffer=16'; \
    echo 'opcache.max_accelerated_files=20000'; \
    echo 'opcache.validate_timestamps=0'; \
    echo 'opcache.revalidate_freq=0'; \
    echo 'opcache.save_comments=1'; \
    echo 'opcache.enable_cli=1'; \
    echo 'opcache.jit_buffer_size=128M'; \
    echo 'opcache.jit=tracing'; \
} > /usr/local/etc/php/conf.d/opcache-prod.ini

# Memory limit — see sizing guidance below
RUN echo 'memory_limit=2G' > /usr/local/etc/php/conf.d/memory.ini

WORKDIR /app

# Copy built application from builder stage
COPY --from=builder /app /app

# Non-root user for security
RUN addgroup -g 1000 app && adduser -u 1000 -G app -s /bin/sh -D app
RUN chown -R app:app /app/var
USER app

EXPOSE 8080

CMD ["php", "public/index.php"]
```

### docker-compose.yml for local development

```yaml
services:
  app:
    build:
      context: .
      target: runtime
    ports:
      - "8080:8080"
    environment:
      APP_ENV: prod
      APP_DEBUG: "false"
      APP_RUNTIME: Monadial\Nexus\Symfony\Runtime\NexusRuntime
      APP_RUNTIME_OPTIONS: '{"host":"0.0.0.0","port":8080,"workers":4,"kernel_pool_size":8,"kernel_pool_max_pending":100}'
      DATABASE_URL: "mysql://app:password@db:3306/app"
      REDIS_DSN: "redis://redis:6379"
    depends_on:
      - db
      - redis
    healthcheck:
      test: ["CMD", "wget", "-qO-", "http://localhost:8080/health"]
      interval: 10s
      timeout: 5s
      retries: 3
      start_period: 15s

  db:
    image: mysql:8.0
    environment:
      MYSQL_DATABASE: app
      MYSQL_USER: app
      MYSQL_PASSWORD: password
      MYSQL_ROOT_PASSWORD: root
    volumes:
      - db-data:/var/lib/mysql

  redis:
    image: redis:7-alpine
    volumes:
      - redis-data:/data

  nginx:
    image: nginx:alpine
    ports:
      - "80:80"
    volumes:
      - ./nginx.conf:/etc/nginx/conf.d/default.conf:ro
      - ./public:/app/public:ro
    depends_on:
      - app

volumes:
  db-data:
  redis-data:
```

---

## public/index.php

The entry point for the nexus-symfony server. The file registers `NexusRuntime` as the Symfony Runtime component and returns a kernel factory closure.

```php
<?php

declare(strict_types=1);

use App\Kernel;
use Monadial\Nexus\Symfony\Runtime\NexusRuntime;

// Register NexusRuntime as the application runtime.
// This is read by symfony/runtime's autoload_runtime.php bootstrap.
$_SERVER['APP_RUNTIME'] = NexusRuntime::class;

require_once dirname(__DIR__) . '/vendor/autoload_runtime.php';

// This closure is invoked once per kernel (once per pool slot per worker).
// The $context array contains merged $_SERVER + $_ENV at the time of invocation.
return static fn(array $context): Kernel => new Kernel(
    $context['APP_ENV'],
    (bool) $context['APP_DEBUG'],
);
```

`NexusRuntime::getResolver()` captures this closure. `NexusRuntime::getRunner()` passes it to `NexusRunner`, which calls it once per `KernelActor` at startup — not once per request. Each kernel boots once and handles many requests for its lifetime.

---

## Environment configuration

### APP_RUNTIME_OPTIONS reference

All options are passed as a JSON object in the `APP_RUNTIME_OPTIONS` environment variable.

```dotenv
# .env.production
APP_ENV=prod
APP_DEBUG=false
APP_RUNTIME=Monadial\Nexus\Symfony\Runtime\NexusRuntime
APP_RUNTIME_OPTIONS={"host":"0.0.0.0","port":8080,"workers":4,"kernel_pool_size":8,"kernel_pool_max_pending":100}
```

| Option | Type | Default | Production recommendation |
|--------|------|---------|--------------------------|
| `host` | string | `0.0.0.0` | `0.0.0.0` — bind all interfaces; let Nginx handle access control |
| `port` | int | `8080` | Any port not in use; `8080` is conventional for app servers behind a proxy |
| `workers` | int | `4` | Number of CPU cores (or 2× for heavily I/O-bound workloads) |
| `kernel_pool_size` | int | `8` | 8–16 for database-backed APIs; 4–8 for CPU-bound; see sizing guide below |
| `kernel_pool_max_pending` | int | `100` | Start at `2 × kernel_pool_size`; increase with monitoring |

### Symfony environment variables

```dotenv
# Required
APP_ENV=prod
APP_DEBUG=false
APP_SECRET=<strong-random-value>

# Database (if using Doctrine)
DATABASE_URL="mysql://user:password@host:3306/dbname?serverVersion=8.0&charset=utf8mb4"

# Redis (if using Symfony Cache with Redis)
REDIS_DSN="redis://redis:6379"

# Mailer (if using Symfony Mailer)
MAILER_DSN="smtp://user:password@smtp.example.com:587"
```

---

## Worker sizing

### Understanding the capacity model

```
                    ┌─────────────────────────────┐
                    │  Process (php public/index.php) │
                    │                             │
                    │  ┌─────────┐ ┌─────────┐   │
 HTTP ─────────────►│  │Worker 0 │ │Worker 1 │ … │
                    │  │         │ │         │   │
                    │  │[k0][k1] │ │[k0][k1] │   │
                    │  │[k2][k3] │ │[k2][k3] │   │
                    │  └─────────┘ └─────────┘   │
                    └─────────────────────────────┘

  Total concurrent requests = workers × kernel_pool_size
```

Each Swoole worker thread is a self-contained unit. Each worker has one `KernelPoolActor` with `kernel_pool_size` kernels. All kernels in a worker share one OS thread via coroutine scheduling.

### Choosing workers

`workers` maps directly to OS threads. More workers means more CPU parallelism.

- Set `workers` to the number of available CPU cores for balanced workloads.
- For heavily I/O-bound workloads (most time spent waiting on database or network), `workers = CPU_count / 2` may be sufficient, freeing cores for other processes.
- For CPU-bound workloads (significant computation in handlers), `workers = CPU_count` maximizes utilization.
- Leave 1–2 cores free on the host for the OS and other system processes.

### Choosing kernel_pool_size

`kernel_pool_size` controls intra-worker concurrency. More kernels absorb more concurrent I/O wait within one worker.

**Rule of thumb for I/O-bound workloads:**

```
kernel_pool_size ≈ average_request_latency_ms ÷ acceptable_scheduling_overhead_ms
```

If the average request spends 10 ms waiting on a database query, and 1 ms of scheduling overhead is acceptable, a pool of 10 keeps the worker's coroutine scheduler busy throughout.

### Capacity planning table

| Scenario | `workers` | `kernel_pool_size` | `max_pending` | Total max concurrent |
|----------|-----------|---------------------|---------------|---------------------|
| Fast API handlers (< 5 ms, no I/O) | CPU cores | 4 | 50 | cores × 4 |
| API with database queries (5–20 ms) | CPU cores | 8 | 100 | cores × 8 |
| API with slow queries or external calls (20–100 ms) | CPU cores | 16 | 200 | cores × 16 |
| Background processing, low RPS | 2 | 4 | 20 | 8 |
| Memory-constrained environment | 2 | 4 | 20 | 8 |

### Memory formula

Each `KernelActor` boots a full Symfony container. Memory per booted kernel varies by application size but is typically 10–20 MB for a mid-size Symfony application.

```
total_memory_mb ≈ workers × (kernel_pool_size + 1) × per_kernel_mb
```

The `+ 1` accounts for the management kernel booted per worker by `NexusRunner` during the `workerStart` phase (this kernel is used to set up the container and is discarded after pool initialization).

Example: 4 workers, pool size 8, 15 MB per kernel:

```
total_memory_mb ≈ 4 × (8 + 1) × 15 = 540 MB
```

Set `memory_limit` comfortably above this value:

```
memory_limit ≈ total_memory_mb × 1.5
```

Profile actual per-kernel memory in the specific application before finalizing production values:

```php
// Add to any controller temporarily during load testing
return new JsonResponse([
    'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
]);
```

---

## Nginx reverse proxy

Nginx sits in front of the Swoole server. It serves static assets directly from `public/` and proxies all PHP requests to the Swoole server.

```nginx
# nginx.conf

upstream nexus {
    server app:8080;
    # Enable keepalive connections to the Swoole backend.
    # Reduces TCP handshake overhead for high-throughput deployments.
    keepalive 64;
}

server {
    listen 80;
    server_name example.com;

    root /app/public;

    # Serve static files directly — never proxy these to PHP
    location ~* \.(css|js|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot|map)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        access_log off;
        try_files $uri =404;
    }

    # Serve favicon and robots.txt directly
    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    # Everything else goes to the Swoole server
    location / {
        proxy_pass         http://nexus;
        proxy_http_version 1.1;

        # Required for keepalive connections
        proxy_set_header Connection "";

        # Pass real client information
        proxy_set_header Host              $host;
        proxy_set_header X-Real-IP         $remote_addr;
        proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;

        # Timeouts — must exceed the ask() timeout in the application (default 30s)
        proxy_connect_timeout 5s;
        proxy_send_timeout    35s;
        proxy_read_timeout    35s;

        # Buffer settings
        proxy_buffering    on;
        proxy_buffer_size  16k;
        proxy_buffers      4 64k;
    }

    # Health check endpoint — no access log
    location = /health {
        proxy_pass http://nexus;
        access_log off;
    }
}
```

### HTTPS configuration

```nginx
server {
    listen 443 ssl http2;
    server_name example.com;

    ssl_certificate     /etc/nginx/ssl/cert.pem;
    ssl_certificate_key /etc/nginx/ssl/key.pem;
    ssl_protocols       TLSv1.2 TLSv1.3;
    ssl_ciphers         HIGH:!aNULL:!MD5;

    # ... same location blocks as above
}

# Redirect HTTP to HTTPS
server {
    listen 80;
    server_name example.com;
    return 301 https://$host$request_uri;
}
```

---

## Process management with Supervisor

Supervisor manages the `php public/index.php` process, restarting it on crash and enabling log rotation.

```ini
# /etc/supervisor/conf.d/nexus.conf

[program:nexus]
command=php -d memory_limit=2G /var/www/app/public/index.php
directory=/var/www/app
user=app
autostart=true
autorestart=true
startretries=3
startsecs=5
stopwaitsecs=35
stopsignal=SIGTERM

; Redirect stdout and stderr to separate log files
stdout_logfile=/var/log/nexus/app.log
stdout_logfile_maxbytes=50MB
stdout_logfile_backups=5
stderr_logfile=/var/log/nexus/app-error.log
stderr_logfile_maxbytes=50MB
stderr_logfile_backups=5

; Environment variables
environment=
    APP_ENV="prod",
    APP_DEBUG="false",
    APP_RUNTIME="Monadial\Nexus\Symfony\Runtime\NexusRuntime",
    APP_RUNTIME_OPTIONS="{\"workers\":4,\"kernel_pool_size\":8,\"kernel_pool_max_pending\":100}"

[supervisord]
logfile=/var/log/supervisor/supervisord.log
pidfile=/var/run/supervisord.pid
```

### Graceful restart without downtime

The nexus-symfony server handles `SIGTERM` by finishing in-flight requests before stopping. To redeploy without dropping requests:

1. Start the new process — it binds to the same port (requires `SO_REUSEPORT` on the socket) or a different port temporarily.
2. Send `SIGTERM` to the old process — `NexusRunner` wires `SIGTERM` via `Process::signal()` to `GracefulShutdownHandler::shutdown()`.
3. The old process finishes all in-flight requests within `shutdown_timeout` seconds, then exits.

With Supervisor:

```bash
# Reload Supervisor configuration and restart the program
supervisorctl reread
supervisorctl update
supervisorctl restart nexus
```

For zero-downtime deploys, run two Supervisor groups (`nexus-a`, `nexus-b`) with Nginx upstream alternating between them, then reload Nginx after the cutover.

---

## Health check endpoint

Add a health check route that the load balancer and Kubernetes probe can call. The endpoint should verify that the application is ready to handle requests, not just that the process is alive.

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController
{
    #[Route('/health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        return new JsonResponse([
            'status' => 'ok',
        ]);
    }

    #[Route('/health/ready', methods: ['GET'])]
    public function ready(): JsonResponse
    {
        // Optionally: verify database connectivity, cache availability, etc.
        // Return 503 if the application is not ready to serve traffic.
        return new JsonResponse([
            'status' => 'ready',
        ]);
    }
}
```

```yaml
# config/routes.yaml
health:
    path: /health
    controller: App\Controller\HealthController::health
    methods: [GET]

health_ready:
    path: /health/ready
    controller: App\Controller\HealthController::ready
    methods: [GET]
```

The `/health` endpoint only checks process liveness. The `/health/ready` endpoint verifies actual readiness — use it as the Kubernetes readiness probe. During worker initialization (the sub-second window after startup when `poolRef` is null), requests return 503; the readiness probe prevents traffic from reaching the pod before it is ready.

---

## Kubernetes deployment

### Deployment manifest

```yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: nexus-app
  labels:
    app: nexus-app
spec:
  replicas: 3
  selector:
    matchLabels:
      app: nexus-app
  template:
    metadata:
      labels:
        app: nexus-app
    spec:
      terminationGracePeriodSeconds: 40   # Must exceed shutdown_timeout (30s) + overhead
      containers:
        - name: app
          image: registry.example.com/nexus-app:latest
          ports:
            - containerPort: 8080
          env:
            - name: APP_ENV
              value: prod
            - name: APP_DEBUG
              value: "false"
            - name: APP_RUNTIME
              value: Monadial\Nexus\Symfony\Runtime\NexusRuntime
          envFrom:
            - configMapRef:
                name: nexus-runtime-options
            - secretRef:
                name: nexus-app-secrets
          resources:
            requests:
              cpu: "1000m"
              memory: "768Mi"
            limits:
              cpu: "2000m"
              memory: "2Gi"
          livenessProbe:
            httpGet:
              path: /health
              port: 8080
            initialDelaySeconds: 15
            periodSeconds: 10
            timeoutSeconds: 5
            failureThreshold: 3
          readinessProbe:
            httpGet:
              path: /health/ready
              port: 8080
            initialDelaySeconds: 5
            periodSeconds: 5
            timeoutSeconds: 3
            failureThreshold: 3
          lifecycle:
            preStop:
              exec:
                # Give the process time to drain in-flight requests before SIGTERM
                command: ["/bin/sh", "-c", "sleep 5"]
```

### ConfigMap for runtime options

```yaml
apiVersion: v1
kind: ConfigMap
metadata:
  name: nexus-runtime-options
data:
  APP_RUNTIME_OPTIONS: '{"host":"0.0.0.0","port":8080,"workers":2,"kernel_pool_size":8,"kernel_pool_max_pending":100}'
```

Kubernetes pods typically have fewer CPUs than bare-metal nodes. Set `workers` to the number of CPU cores requested, not the host core count.

### Horizontal Pod Autoscaler

nexus-symfony scales horizontally by adding pods. Each pod is a self-contained Swoole server. The HPA scales on CPU utilization or custom metrics.

```yaml
apiVersion: autoscaling/v2
kind: HorizontalPodAutoscaler
metadata:
  name: nexus-app-hpa
spec:
  scaleTargetRef:
    apiVersion: apps/v1
    kind: Deployment
    name: nexus-app
  minReplicas: 2
  maxReplicas: 20
  metrics:
    - type: Resource
      resource:
        name: cpu
        target:
          type: Utilization
          averageUtilization: 70
    - type: Resource
      resource:
        name: memory
        target:
          type: Utilization
          averageUtilization: 80
```

For latency-based scaling, use a custom metric from Prometheus or an NGINX ingress latency metric:

```yaml
  metrics:
    - type: External
      external:
        metric:
          name: nginx_ingress_controller_requests_latency_ms
          selector:
            matchLabels:
              ingress: nexus-app
        target:
          type: AverageValue
          averageValue: "100"  # Scale up when p95 latency exceeds 100ms
```

### Service

```yaml
apiVersion: v1
kind: Service
metadata:
  name: nexus-app
spec:
  selector:
    app: nexus-app
  ports:
    - protocol: TCP
      port: 80
      targetPort: 8080
  type: ClusterIP
```

---

## Graceful shutdown

`NexusRunner` registers a `SIGTERM` handler via `Swoole\Process::signal()`. On `SIGTERM`:

1. The handler calls `GracefulShutdownHandler::shutdown()`.
2. `GracefulShutdownHandler` calls `ActorSystem::shutdown(timeout)` where `timeout` is the configured `shutdown_timeout` (default: 30 seconds).
3. The actor system sends `PoisonPill` to all top-level actors. Each actor finishes its current message and stops. `KernelPoolActor` stops only after all in-flight kernels complete their current requests.
4. After `shutdown_timeout` seconds, the actor system forces termination regardless of in-flight work.
5. `ActorSystem::shutdown()` returns. The process exits.

### Configuration

```yaml
# config/packages/nexus.yaml
nexus:
    shutdown_timeout: 30   # Seconds to wait for graceful drain
```

### Kubernetes terminationGracePeriodSeconds

`terminationGracePeriodSeconds` must be greater than `shutdown_timeout` to allow the graceful drain to complete before Kubernetes sends `SIGKILL`.

```
terminationGracePeriodSeconds > shutdown_timeout + preStop_sleep + pod_overhead
```

With `shutdown_timeout: 30`, `preStop: sleep 5`, and 5 seconds of pod overhead:

```
terminationGracePeriodSeconds = 40
```

If requests regularly take longer than 30 seconds (e.g., large file processing), increase `shutdown_timeout` accordingly and adjust `terminationGracePeriodSeconds` to match.

---

## Logging

### Monolog configuration for production

```yaml
# config/packages/prod/monolog.yaml
monolog:
    handlers:
        main:
            type:         stream
            path:         php://stdout
            level:        info
            formatter:    monolog.formatter.json
            channels:     ["!event"]

        console:
            type:         console
            process_ppu:  true
            channels:     ["!event", "!doctrine"]
```

### Structured JSON output

For log aggregation (Datadog, Elastic, Loki), use JSON formatting so logs are machine-parseable:

```yaml
# config/packages/prod/monolog.yaml
monolog:
    handlers:
        main:
            type:      stream
            path:      php://stdout
            level:     info
            formatter: Monolog\Formatter\JsonFormatter
```

### Worker identification in logs

The actor system name (`nexus-worker-{id}`) is available in each worker's container. Inject it into log records for correlation:

```php
<?php

declare(strict_types=1);

namespace App\Logging;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;

#[AsMonologProcessor]
final class WorkerProcessor
{
    private ?string $workerName = null;

    public function __construct(private readonly ?ActorSystem $system = null) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        if ($this->workerName === null && $this->system !== null) {
            $this->workerName = $this->system->name();
        }

        return $record->with(
            extra: array_merge($record->extra, [
                'worker' => $this->workerName ?? 'unknown',
            ]),
        );
    }
}
```

```yaml
# config/services.yaml
App\Logging\WorkerProcessor:
    tags:
        - { name: monolog.processor }
```

This adds a `worker` key to every log record, making it trivial to filter logs by worker in Kibana, Grafana Loki, or Datadog.

### Log levels

| Environment | Minimum level | Notes |
|-------------|--------------|-------|
| `dev` | `debug` | All levels including debug SQL queries |
| `staging` | `info` | Remove debug noise |
| `prod` | `info` | Consider `warning` if log volume is high |

Avoid `debug` in production — Doctrine logs every SQL query at debug level, which adds significant log volume under load.

---

## Static asset handling

nexus-symfony is a PHP application server. It handles PHP requests only. Static assets — CSS, JavaScript, images, fonts — must be served by Nginx directly from the `public/` directory without routing through PHP.

### Nginx static file configuration

```nginx
location ~* \.(css|js|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot|map|json)$ {
    root /app/public;
    expires 1y;
    add_header Cache-Control "public, immutable";
    add_header Vary Accept-Encoding;
    access_log off;
    gzip_static on;   # Serve pre-compressed .gz files if present
    try_files $uri =404;
}
```

### Symfony Webpack Encore / Vite

When using Webpack Encore or Vite, build assets as part of the Docker build and copy them to `public/build/` or `public/assets/`:

```dockerfile
# In the builder stage, before composer install
FROM node:20-alpine AS asset-builder
WORKDIR /app
COPY package.json yarn.lock ./
RUN yarn install --frozen-lockfile
COPY assets/ assets/
COPY webpack.config.js ./
RUN yarn build

# In the runtime stage
COPY --from=asset-builder /app/public/build /app/public/build
```

Use content-hashed filenames (Encore and Vite both produce these by default) so Nginx cache headers can be set to `immutable` without cache-busting concerns on deploy.

### Assets that should never go through PHP

| File type | Served by | Cache-Control |
|-----------|-----------|---------------|
| `.css`, `.js` (production builds) | Nginx | `public, immutable, max-age=31536000` |
| Images (`.png`, `.jpg`, `.svg`, `.ico`) | Nginx | `public, max-age=86400` |
| Web fonts (`.woff2`, `.ttf`) | Nginx | `public, immutable, max-age=31536000` |
| `robots.txt`, `sitemap.xml` | Nginx | `public, max-age=3600` |
| API responses, dynamic HTML | Nexus PHP server | `no-cache` or application-specific |

---

## Performance checklist

Before directing production traffic to the deployment, verify all items:

- [ ] **ZTS PHP binary** — `php -i | grep 'Thread Safety'` shows `enabled`
- [ ] **Swoole with thread support** — `php --ri swoole | grep thread` shows `enabled`
- [ ] **Swoole version** — 6.0 or later
- [ ] **OPcache enabled** — `php -i | grep 'opcache.enable '` shows `1`
- [ ] **OPcache `validate_timestamps=0`** — disabled in production to avoid stat() calls per request
- [ ] **JIT enabled** — `opcache.jit=tracing` and `opcache.jit_buffer_size` set
- [ ] **No Xdebug** — `php -m | grep xdebug` produces no output
- [ ] **`APP_ENV=prod`** — Symfony debug toolbar, profiler, and verbose error pages are disabled
- [ ] **`APP_DEBUG=false`** — Exception pages show generic errors, not stack traces
- [ ] **Workers sized to CPU cores** — `workers` in `APP_RUNTIME_OPTIONS` matches available CPUs
- [ ] **`kernel_pool_size` profiled** — sized based on measured request latency distribution
- [ ] **`memory_limit` sufficient** — set above `workers × (kernel_pool_size + 1) × per_kernel_mb × 1.5`
- [ ] **Nginx serving static assets** — static files never reach PHP
- [ ] **Nginx keepalive to upstream** — `keepalive 64` in the upstream block
- [ ] **Health check responding** — `curl http://localhost:8080/health` returns 200
- [ ] **Readiness probe passing** — `curl http://localhost:8080/health/ready` returns 200
- [ ] **Graceful shutdown tested** — `kill -SIGTERM <pid>` drains in-flight requests within `shutdown_timeout`
- [ ] **`terminationGracePeriodSeconds` > `shutdown_timeout`** — Kubernetes allows full drain before SIGKILL
- [ ] **Log output is structured JSON** — log aggregation system can parse and index all fields
- [ ] **Connection pools configured** — if using Redis or a database connection pool, pool size ≥ `kernel_pool_size`
- [ ] **OPcache preloading configured** (optional but recommended) — `opcache.preload` set to the Symfony preload script

### OPcache preloading

Symfony generates a preload script during cache warmup. Enable it to pre-compile frequently-used classes into OPcache on startup:

```ini
; /usr/local/etc/php/conf.d/opcache-prod.ini
opcache.preload=/app/var/cache/prod/App_KernelProdContainer.preload.php
opcache.preload_user=app
```

Preloading reduces the latency of the first request after startup. With preloading, all Symfony container classes are already compiled when the first request arrives.
