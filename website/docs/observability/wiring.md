---
sidebar_position: 8
title: Full Wiring Guide
---

# Full Wiring Guide

This page shows how to wire all Nexus observability satellites together in a production application. Each section is independent — add the satellites you need.

## HTTP pipeline

Requires `nexus-observability-http`.

Register `ServerSpanMiddleware` **first** in your middleware stack so it wraps all downstream middleware and the route handler. Register `HttpMetricsListener` as a PSR-14 listener to record request metrics.

```php title="http-wiring.php" verify:lint-only
use Monadial\Nexus\Observability\Http\HttpMetricsListener;
use Monadial\Nexus\Observability\Http\ServerSpanMiddleware;

// ServerSpanMiddleware must come first — it creates the root server span
$app->middleware(new ServerSpanMiddleware($observability));

// HttpMetricsListener records duration and active-request count
$dispatcher->addListener(HttpMetricsListener::class, new HttpMetricsListener($observability));
```

## Persistence stores

Requires `nexus-observability-persistence`.

Wrap your event store and snapshot store with the tracing decorators. Pass the `Observability` provider so child spans appear under the actor's message span.

```php title="persistence-wiring.php" verify:lint-only
use Monadial\Nexus\Observability\Persistence\TracingDurableStateStore;
use Monadial\Nexus\Observability\Persistence\TracingEventStore;
use Monadial\Nexus\Observability\Persistence\TracingSnapshotStore;

$eventStore = new TracingEventStore($innerEventStore, $observability);
$snapshotStore = new TracingSnapshotStore($innerSnapshotStore, $observability);
$durableStateStore = new TracingDurableStateStore($innerDurableStateStore, $observability);
```

Pass `$eventStore` and `$snapshotStore` to `EventSourcedBehavior::create()` (or `$durableStateStore` to `DurableStateBehavior::create()`) as usual.

## Worker pool transport

Requires `nexus-observability-worker-pool`.

Wrap the transport before passing it to `WorkerNode`. `TracingWorkerTransport` injects W3C Trace Context into the `Envelope::metadata` of every cross-worker message so the receiving worker's actor span becomes a child of the sending span.

```php title="worker-pool-wiring.php" verify:lint-only
use Monadial\Nexus\Observability\WorkerPool\TracingWorkerTransport;

$transport = new TracingWorkerTransport($innerTransport, $observability);
```

## DBAL instrumentation

Requires `nexus-observability-doctrine`.

Add `TracingDriverMiddleware` to the DBAL `Configuration` before creating connections. Subscribe `DbalPoolMetricsListener` to the PSR-14 dispatcher to record pool metrics.

```php title="dbal-wiring.php" verify:lint-only
use Doctrine\DBAL\Configuration as DbalConfiguration;
use Monadial\Nexus\Observability\Doctrine\DbalPoolMetricsListener;
use Monadial\Nexus\Observability\Doctrine\Sql\TracingDriverMiddleware;

$dbalConfig = new DbalConfiguration();
$dbalConfig->setMiddlewares([new TracingDriverMiddleware($observability)]);

$dispatcher->addListener(DbalPoolMetricsListener::class, new DbalPoolMetricsListener($observability));
```

`TracingDriverMiddleware` wraps DBAL at the driver level, so every SQL query executed from within an active span produces a child span automatically.

## Doctrine ORM pool

Requires `nexus-observability-doctrine`.

Subscribe `OrmPoolMetricsListener` to record entity manager pool usage:

```php title="orm-wiring.php" verify:lint-only
use Monadial\Nexus\Observability\Doctrine\OrmPoolMetricsListener;

$dispatcher->addListener(OrmPoolMetricsListener::class, new OrmPoolMetricsListener($observability));
```

## Actor system metrics

Requires `nexus-observability-actor`.

Instantiate `ActorSystemMetrics` and call `register()` after the `ActorSystem` is created. It registers observable gauges for live actors, dead letters, and system running state.

```php title="actor-metrics-wiring.php" verify:lint-only
use Monadial\Nexus\Observability\Actor\ActorSystemMetrics;

$metrics = new ActorSystemMetrics($observability, $system);
$metrics->register();
```

## Swoole integration

Requires `nexus-observability-swoole`.

Call `SwooleContextRegistrar::install()` **once at process start**, before any coroutines are created. Then register per-worker metrics inside your `onWorkerStart` callback using instance methods on `SwooleAdminMetrics`.

```php title="swoole-wiring.php" verify:lint-only
use Monadial\Nexus\Observability\Swoole\SwooleAdminMetrics;
use Monadial\Nexus\Observability\Swoole\SwooleContextRegistrar;

// Must be called before any Swoole coroutines are created
SwooleContextRegistrar::install();

// Call inside onWorkerStart for each worker process/thread
$swooleMetrics = new SwooleAdminMetrics($observability);
$swooleMetrics->registerCoroutineGauges();
$swooleMetrics->registerServerGauges($server);
```

`SwooleContextRegistrar::install()` replaces Swoole's default coroutine context storage with an OTel-aware implementation that carries the active span across `Co\go()` boundaries and channel switches. Without it, OTel context is lost when a coroutine suspends.

## Logger correlation

Add `TraceCorrelationProcessor` to your logger setup. See [Logs & Trace Correlation](./logs.md) for details.

## Complete wiring example

A full Swoole production bootstrap with all satellites:

```php title="bootstrap-full.php" verify:lint-only
use Doctrine\DBAL\Configuration as DbalConfiguration;
use Monadial\Nexus\App\NexusApp;
use Monadial\Nexus\Observability\Actor\ActorSystemMetrics;
use Monadial\Nexus\Observability\Config\ObservabilityConfig;
use Monadial\Nexus\Observability\Doctrine\DbalPoolMetricsListener;
use Monadial\Nexus\Observability\Doctrine\OrmPoolMetricsListener;
use Monadial\Nexus\Observability\Doctrine\Sql\TracingDriverMiddleware;
use Monadial\Nexus\Observability\Http\HttpMetricsListener;
use Monadial\Nexus\Observability\Http\ServerSpanMiddleware;
use Monadial\Nexus\Observability\Logger\TraceCorrelationProcessor;
use Monadial\Nexus\Observability\Otel\ObservabilityFactory;
use Monadial\Nexus\Observability\Persistence\TracingEventStore;
use Monadial\Nexus\Observability\Persistence\TracingSnapshotStore;
use Monadial\Nexus\Observability\Swoole\SwooleAdminMetrics;
use Monadial\Nexus\Observability\Swoole\SwooleContextRegistrar;
use Monadial\Nexus\Observability\WorkerPool\TracingWorkerTransport;
use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;

// Step 1: Install Swoole coroutine context storage before any coroutines start
SwooleContextRegistrar::install();

// Step 2: Build the observability provider from OTEL_* env vars
$observability = ObservabilityFactory::fromConfig(ObservabilityConfig::fromEnv($_SERVER));

// Step 3: HTTP middleware (register ServerSpanMiddleware first)
$app->middleware(new ServerSpanMiddleware($observability));
$dispatcher->addListener(HttpMetricsListener::class, new HttpMetricsListener($observability));

// Step 4: DBAL — add tracing middleware before creating any connections
$dbalConfig = new DbalConfiguration();
$dbalConfig->setMiddlewares([new TracingDriverMiddleware($observability)]);
$dispatcher->addListener(DbalPoolMetricsListener::class, new DbalPoolMetricsListener($observability));

// Step 5: ORM pool metrics
$dispatcher->addListener(OrmPoolMetricsListener::class, new OrmPoolMetricsListener($observability));

// Step 6: Persistence stores wrapped with tracing decorators
$eventStore = new TracingEventStore($innerEventStore, $observability);
$snapshotStore = new TracingSnapshotStore($innerSnapshotStore, $observability);

// Step 7: Worker pool transport with trace context propagation
$transport = new TracingWorkerTransport($innerTransport, $observability);

// Step 8: Boot the application
NexusApp::create('my-app')
    ->withObservability($observability)
    ->onStart(static function ($system) use ($observability, $server): void {
        $metrics = new ActorSystemMetrics($observability, $system);
        $metrics->register();
        $swooleMetrics = new SwooleAdminMetrics($observability);
        $swooleMetrics->registerCoroutineGauges();
        $swooleMetrics->registerServerGauges($server);
    })
    ->run(new SwooleRuntime());
```

:::info
Steps 3–7 are independent of each other. Add only the satellites relevant to your application. If you do not use DBAL, skip steps 4 and 5. If you run on FiberRuntime instead of SwooleRuntime, skip the Swoole steps entirely.
:::
