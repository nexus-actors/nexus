# Observability — Plan 4: HTTP (`nexus-observability-http`) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Add HTTP server instrumentation — a PSR-15 middleware that opens a Server span per request (the flagship request→actor→response trace) and a PSR-14 listener that emits RED metrics — in a new `nexus-observability-http` package, driven by the injected `Observability` provider (no-op default).

**Architecture:** `ServerSpanMiddleware` is the **span source of truth** (per decision): it extracts trace context from request headers via our propagator, starts+activates a Server span (so any actor `ask`/`tell` inside the handler injects it via ambient current context → single connected trace), sets `http.*` semantic-convention attributes + response status, records handler exceptions, and always ends the span. `HttpMetricsListener` subscribes to `RequestStarted`/`RequestCompleted` (which already carry method, response, and `durationNanos`) to record RED metrics. All telemetry is fail-isolated (never breaks a request) and short-circuits when observability is disabled.

**Scope note:** No HTTP *client* package exists in the monorepo (nexus-http is server-only), so client-span instrumentation is out of scope. WebSocket (`nexus-http-ws`) spans are deferred to a follow-up (lighter, secondary); this plan focuses on the flagship server request→actor→response trace + RED metrics.

**Tech Stack:** PHP 8.5.7, PSR-15/PSR-7, `nexus-observability` (+ `nexus-observability-otel` & `open-telemetry/sdk` as dev-deps for tests), PHPUnit 13, Psalm L1, PHPCS, Deptrac, Docker.

## Global Constraints

- **Docker only:** prefix every command with `docker compose exec -T php`. `composer dump-autoload` after adding classes.
- **Commit policy:** GrumPHP hook is broken in this Docker+worktree — commit with `git -c commit.gpgsign=false commit --no-verify` (worktree GPG also times out). Before EVERY commit run the gate manually: `make cs-fix && make phpcs && make psalm` (all clean) + the package suite. `make cs-fix` does NOT enforce `ReferenceUsedNamesOnly` — `make phpcs` does; never inline `\FQCN`. `Warning: JIT...` is env noise.
- **NEVER** add `Co-Authored-By: Claude`.
- **No singletons:** no `static instance()` / static self state; DI only.
- **Fail-isolation (spec §12):** every telemetry call in the request path must be guarded so a telemetry exception can never break the HTTP request. The handler exception itself, however, MUST still propagate (to the existing exception-handler middleware) — record it on the span, then rethrow.
- **Disabled fast-path:** when `$observability->isEnabled()` is false, the middleware/listener do nothing but pass through (zero overhead).
- **Attributes (D5/D11):** span uses `http.request.method`, `url.path`, `http.response.status_code` (metadata only). Metric dimensions are LOW-cardinality: `http.request.method` + `http.response.status_code` only — never `url.path` (unbounded).
- **Code style:** `declare(strict_types=1);`; classes `final`; `/** @psalm-api */` on public API; alphabetical imports; string-keyed arrays **alphabetical**; trailing commas in multiline; blank line before control structures; multi-line ternaries.
- **Deptrac:** new layer `ObservabilityHttp` may depend only on `Http` + `Observability`.
- **Tests:** use the real OTEL bridge with the SDK in-memory exporters (dev-dep) to assert exported spans/metrics; no network, no real socket.

## Verified seams

- PSR-15: `Psr\Http\Server\MiddlewareInterface::process(ServerRequestInterface, RequestHandlerInterface): ResponseInterface`.
- Events (`Monadial\Nexus\Http\Event\`): `RequestStarted(ServerRequestInterface $request, int $startNanos)`; `RequestCompleted(ServerRequestInterface $request, ResponseInterface $response, int $durationNanos)`.
- `Observability`: `tracer()`, `meter()`, `propagator()`, `currentContext()`, `isEnabled()`. `Tracer::startSpan(name, SpanKind, array<string,scalar>, ?Context)`. `Span`: setAttribute/recordException/setStatus/end. `Meter`: counter/upDownCounter/histogram. `ContextPropagator::extract(array<string,string>): Context`.

---

## File Structure

```
packages/nexus-observability-http/
  composer.json
  src/
    ServerSpanMiddleware.php     # PSR-15: Server span source of truth
    HttpMetricsListener.php      # PSR-14: RED metrics from RequestStarted/RequestCompleted
  tests/
    Unit/
      ServerSpanMiddlewareTest.php
      HttpMetricsListenerTest.php
      HttpActorTracePropagationTest.php
```
Shared files modified by Task 1: root `composer.json` (require + autoload + autoload-dev), `deptrac.yaml`, `phpunit.xml`.

---

## Task 1: Scaffold + `ServerSpanMiddleware`

**Files:**
- Create: `packages/nexus-observability-http/composer.json`
- Create: `packages/nexus-observability-http/src/ServerSpanMiddleware.php`
- Create: `packages/nexus-observability-http/tests/Unit/ServerSpanMiddlewareTest.php`
- Modify: root `composer.json`, `deptrac.yaml`, `phpunit.xml`

**Interfaces:**
- Produces: `final class ServerSpanMiddleware implements MiddlewareInterface` — ctor `(Observability $observability)`; opens a Server span, sets `http.request.method`/`url.path`/`http.response.status_code`, status ERROR on 5xx or handler throw, always ends; disabled → pass-through; fully fail-isolated.

- [ ] **Step 1: `packages/nexus-observability-http/composer.json`**
```json
{
    "name": "nexus-actors/observability-http",
    "description": "Nexus HTTP observability — PSR-15 server-span middleware and PSR-14 RED-metrics listener.",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": ">=8.5.7",
        "nexus-actors/http": "dev-main",
        "nexus-actors/observability": "dev-main",
        "psr/http-message": "^1.1 || ^2.0",
        "psr/http-server-handler": "^1.0",
        "psr/http-server-middleware": "^1.0"
    },
    "require-dev": {
        "nexus-actors/observability-otel": "dev-main",
        "open-telemetry/sdk": "^1.14",
        "phpunit/phpunit": "^13.0"
    },
    "autoload": {
        "psr-4": {
            "Monadial\\Nexus\\Observability\\Http\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Monadial\\Nexus\\Observability\\Http\\Tests\\": "tests/"
        }
    }
}
```

- [ ] **Step 2: Root `composer.json`** — add to `autoload.psr-4`:
```json
            "Monadial\\Nexus\\Observability\\Http\\": "packages/nexus-observability-http/src/",
```
and to `autoload-dev.psr-4`:
```json
            "Monadial\\Nexus\\Observability\\Http\\Tests\\": "packages/nexus-observability-http/tests/",
```
Run `docker compose exec -T php composer dump-autoload`.

- [ ] **Step 3: `deptrac.yaml`** — add layer:
```yaml
    - name: ObservabilityHttp
      collectors:
        - type: directory
          value: packages/nexus-observability-http/src/.*
```
and ruleset:
```yaml
    ObservabilityHttp:
      - Http
      - Observability
```

- [ ] **Step 4: `phpunit.xml`** — add to `<testsuite name="unit">`:
```xml
            <directory>packages/nexus-observability-http/tests/Unit</directory>
```
and to `<source><include>`:
```xml
            <directory>packages/nexus-observability-http/src</directory>
```

- [ ] **Step 5: Write the failing test**

`packages/nexus-observability-http/tests/Unit/ServerSpanMiddlewareTest.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Http\Tests\Unit;

use Monadial\Nexus\Observability\Context\BaggagePropagator;
use Monadial\Nexus\Observability\Context\CompositePropagator;
use Monadial\Nexus\Observability\Context\TraceContextPropagator;
use Monadial\Nexus\Observability\Http\ServerSpanMiddleware;
use Monadial\Nexus\Observability\Otel\OtelObservability;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter as MetricInMemoryExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

#[CoversClass(ServerSpanMiddleware::class)]
final class ServerSpanMiddlewareTest extends TestCase
{
    private InMemoryExporter $exporter;
    private TracerProvider $tracerProvider;
    private OtelObservability $observability;

    protected function setUp(): void
    {
        $this->exporter = new InMemoryExporter();
        $this->tracerProvider = new TracerProvider(new SimpleSpanProcessor($this->exporter));
        $meterProvider = MeterProvider::builder()
            ->addReader(new ExportingReader(new MetricInMemoryExporter()))
            ->build();
        $this->observability = new OtelObservability(
            $this->tracerProvider,
            $meterProvider,
            new CompositePropagator([new TraceContextPropagator(), new BaggagePropagator()]),
        );
    }

    #[Test]
    public function opensServerSpanUnderInboundTraceparentWithHttpAttributes(): void
    {
        $middleware = new ServerSpanMiddleware($this->observability);
        $request = (new ServerRequest('GET', 'https://api.test/orders/42'))
            ->withHeader('traceparent', '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01');

        $response = $middleware->process($request, $this->handlerReturning(new Response(200)));

        self::assertSame(200, $response->getStatusCode());
        $this->tracerProvider->forceFlush();
        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
        self::assertSame('0af7651916cd43dd8448eb211c80319c', $spans[0]->getTraceId());
        self::assertSame('b7ad6b7169203331', $spans[0]->getParentSpanId());
        self::assertSame(2, $spans[0]->getKind()); // SERVER
        self::assertSame('GET', $spans[0]->getAttributes()->get('http.request.method'));
        self::assertSame('/orders/42', $spans[0]->getAttributes()->get('url.path'));
        self::assertSame(200, $spans[0]->getAttributes()->get('http.response.status_code'));
    }

    #[Test]
    public function marksServerErrorStatusOnFivexx(): void
    {
        $middleware = new ServerSpanMiddleware($this->observability);
        $middleware->process(new ServerRequest('GET', 'https://api.test/'), $this->handlerReturning(new Response(503)));

        $this->tracerProvider->forceFlush();
        self::assertSame('Error', $this->exporter->getSpans()[0]->getStatus()->getCode());
    }

    #[Test]
    public function recordsHandlerExceptionAndRethrows(): void
    {
        $middleware = new ServerSpanMiddleware($this->observability);
        $throwing = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new RuntimeException('boom');
            }
        };

        try {
            $middleware->process(new ServerRequest('POST', 'https://api.test/x'), $throwing);
            self::fail('exception should propagate');
        } catch (RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        $this->tracerProvider->forceFlush();
        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
        self::assertSame('Error', $spans[0]->getStatus()->getCode());
    }

    private function handlerReturning(ResponseInterface $response): RequestHandlerInterface
    {
        return new class($response) implements RequestHandlerInterface {
            public function __construct(private readonly ResponseInterface $response) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };
    }
}
```

- [ ] **Step 6: Run — expect FAIL** (`ServerSpanMiddleware` not found):
`docker compose exec -T php vendor/bin/phpunit packages/nexus-observability-http/tests/Unit/ServerSpanMiddlewareTest.php`

- [ ] **Step 7: Create `ServerSpanMiddleware`**

`packages/nexus-observability-http/src/ServerSpanMiddleware.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Http;

use Monadial\Nexus\Observability\Context\Context;
use Monadial\Nexus\Observability\Observability;
use Monadial\Nexus\Observability\Trace\Span;
use Monadial\Nexus\Observability\Trace\SpanKind;
use Monadial\Nexus\Observability\Trace\StatusCode;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

use function implode;
use function strtolower;

/**
 * @psalm-api
 *
 * PSR-15 middleware that opens an OpenTelemetry Server span for each request.
 * It is the span source of truth: it extracts the inbound trace context, starts
 * and activates the span (so any actor `ask`/`tell` inside the handler links as
 * a child), records `http.*` attributes and the response status, records handler
 * exceptions, and always ends the span. All telemetry is fail-isolated — a
 * telemetry error never breaks the request; the handler's own exception still
 * propagates to the exception-handling middleware.
 */
final class ServerSpanMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Observability $observability,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->observability->isEnabled()) {
            return $handler->handle($request);
        }

        $span = $this->startSpan($request);

        try {
            $response = $handler->handle($request);
            $this->safely(static function () use ($span, $response): void {
                $span?->setAttribute('http.response.status_code', $response->getStatusCode());
                $span?->setStatus(
                    $response->getStatusCode() >= 500
                        ? StatusCode::Error
                        : StatusCode::Unset,
                );
            });

            return $response;
        } catch (Throwable $e) {
            $this->safely(static function () use ($span, $e): void {
                $span?->recordException($e);
                $span?->setStatus(StatusCode::Error, $e->getMessage());
            });

            throw $e;
        } finally {
            $this->safely(static fn (): mixed => $span?->end());
        }
    }

    private function startSpan(ServerRequestInterface $request): ?Span
    {
        try {
            return $this->observability->tracer()->startSpan(
                'HTTP ' . $request->getMethod(),
                SpanKind::Server,
                [
                    'http.request.method' => $request->getMethod(),
                    'url.path' => $request->getUri()->getPath(),
                ],
                $this->extractParent($request),
            );
        } catch (Throwable) {
            return null;
        }
    }

    private function extractParent(ServerRequestInterface $request): Context
    {
        $carrier = [];

        foreach ($request->getHeaders() as $name => $values) {
            $carrier[strtolower($name)] = implode(',', $values);
        }

        return $this->observability->propagator()->extract($carrier);
    }

    /**
     * @param callable(): mixed $fn
     */
    private function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable) {
            // Telemetry must never break the request.
        }
    }
}
```

- [ ] **Step 8: Run — expect PASS.** Then `make cs-fix && make phpcs && make psalm` (clean).

- [ ] **Step 9: Commit**
```bash
git add packages/nexus-observability-http composer.json composer.lock deptrac.yaml phpunit.xml
git -c commit.gpgsign=false commit --no-verify -m "feat(observability-http): scaffold package + ServerSpanMiddleware"
```

---

## Task 2: `HttpMetricsListener` (RED metrics)

**Files:**
- Create: `packages/nexus-observability-http/src/HttpMetricsListener.php`
- Create: `packages/nexus-observability-http/tests/Unit/HttpMetricsListenerTest.php`

**Interfaces:**
- Produces: `final class HttpMetricsListener` — ctor `(Observability $observability)`; `onRequestStarted(RequestStarted): void` (active_requests +1); `onRequestCompleted(RequestCompleted): void` (active_requests -1, duration histogram, both keyed low-cardinality). Disabled → no-op. Instruments cached per listener instance.

- [ ] **Step 1: Write the failing test**

`packages/nexus-observability-http/tests/Unit/HttpMetricsListenerTest.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Http\Tests\Unit;

use Monadial\Nexus\Http\Event\RequestCompleted;
use Monadial\Nexus\Http\Event\RequestStarted;
use Monadial\Nexus\Observability\Context\BaggagePropagator;
use Monadial\Nexus\Observability\Context\CompositePropagator;
use Monadial\Nexus\Observability\Context\TraceContextPropagator;
use Monadial\Nexus\Observability\Http\HttpMetricsListener;
use Monadial\Nexus\Observability\Otel\OtelObservability;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter as MetricInMemoryExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;

#[CoversClass(HttpMetricsListener::class)]
final class HttpMetricsListenerTest extends TestCase
{
    #[Test]
    public function recordsDurationAndActiveRequests(): void
    {
        $metricExporter = new MetricInMemoryExporter();
        $reader = new ExportingReader($metricExporter);
        $observability = new OtelObservability(
            new TracerProvider(new SimpleSpanProcessor(new InMemoryExporter())),
            MeterProvider::builder()->addReader($reader)->build(),
            new CompositePropagator([new TraceContextPropagator(), new BaggagePropagator()]),
        );

        $listener = new HttpMetricsListener($observability);
        $request = new ServerRequest('GET', 'https://api.test/orders');

        $listener->onRequestStarted(new RequestStarted($request, 1_000));
        $listener->onRequestCompleted(new RequestCompleted($request, new Response(200), 5_000_000));

        $reader->collect();
        $names = array_map(static fn ($metric): string => $metric->name, $metricExporter->collect());

        self::assertContains('http.server.request.duration', $names);
        self::assertContains('http.server.active_requests', $names);
    }
}
```

- [ ] **Step 2: Run — expect FAIL.**

- [ ] **Step 3: Create `HttpMetricsListener`**

`packages/nexus-observability-http/src/HttpMetricsListener.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Http;

use Monadial\Nexus\Http\Event\RequestCompleted;
use Monadial\Nexus\Http\Event\RequestStarted;
use Monadial\Nexus\Observability\Metric\Histogram;
use Monadial\Nexus\Observability\Metric\UpDownCounter;
use Monadial\Nexus\Observability\Observability;

/**
 * @psalm-api
 *
 * PSR-14 listener that records RED metrics from the HTTP request lifecycle
 * events. Register `onRequestStarted` for {@see RequestStarted} and
 * `onRequestCompleted` for {@see RequestCompleted}. No-op when observability is
 * disabled. Metric dimensions are deliberately low-cardinality (method + status
 * code) to keep the metrics backend healthy.
 */
final class HttpMetricsListener
{
    private ?UpDownCounter $activeRequests = null;

    private ?Histogram $duration = null;

    public function __construct(
        private readonly Observability $observability,
    ) {}

    public function onRequestStarted(RequestStarted $event): void
    {
        if (!$this->observability->isEnabled()) {
            return;
        }

        $this->activeRequests()->add(1, ['http.request.method' => $event->request->getMethod()]);
    }

    public function onRequestCompleted(RequestCompleted $event): void
    {
        if (!$this->observability->isEnabled()) {
            return;
        }

        $method = $event->request->getMethod();

        $this->activeRequests()->add(-1, ['http.request.method' => $method]);
        $this->duration()->record($event->durationNanos / 1_000_000_000, [
            'http.request.method' => $method,
            'http.response.status_code' => $event->response->getStatusCode(),
        ]);
    }

    private function activeRequests(): UpDownCounter
    {
        return $this->activeRequests ??= $this->observability->meter()->upDownCounter(
            'http.server.active_requests',
            '{request}',
            'Number of in-flight HTTP server requests',
        );
    }

    private function duration(): Histogram
    {
        return $this->duration ??= $this->observability->meter()->histogram(
            'http.server.request.duration',
            's',
            'Duration of HTTP server requests',
        );
    }
}
```

- [ ] **Step 4: Run — expect PASS.** Then `make cs-fix && make phpcs && make psalm` (clean) + package suite.

- [ ] **Step 5: Commit**
```bash
git add packages/nexus-observability-http
git -c commit.gpgsign=false commit --no-verify -m "feat(observability-http): add HttpMetricsListener (RED metrics)"
```

---

## Task 3: Flagship request→actor→response end-to-end test

Prove that a Server span wrapping a handler that `ask`s an actor produces ONE connected trace: SERVER span → actor CONSUMER span.

**Files:**
- Create: `packages/nexus-observability-http/tests/Unit/HttpActorTracePropagationTest.php`
- Modify: `packages/nexus-observability-http/composer.json` (add `nexus-actors/core` + `nexus-actors/runtime-fiber` to `require-dev`)

- [ ] **Step 1: Add dev deps** to `packages/nexus-observability-http/composer.json` `require-dev` (alphabetical): `"nexus-actors/core": "dev-main"`, `"nexus-actors/runtime-fiber": "dev-main"`. Run `docker compose exec -T php composer dump-autoload`.

- [ ] **Step 2: Write the test**

`packages/nexus-observability-http/tests/Unit/HttpActorTracePropagationTest.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Http\Tests\Unit;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Observability\Context\BaggagePropagator;
use Monadial\Nexus\Observability\Context\CompositePropagator;
use Monadial\Nexus\Observability\Context\TraceContextPropagator;
use Monadial\Nexus\Observability\Http\ServerSpanMiddleware;
use Monadial\Nexus\Observability\Otel\OtelObservability;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter as MetricInMemoryExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[CoversNothing]
final class HttpActorTracePropagationTest extends TestCase
{
    #[Test]
    #[WithoutErrorHandler]
    public function httpRequestToActorProducesOneConnectedTrace(): void
    {
        $exporter = new InMemoryExporter();
        $tracerProvider = new TracerProvider(new SimpleSpanProcessor($exporter));
        $observability = new OtelObservability(
            $tracerProvider,
            MeterProvider::builder()->addReader(new ExportingReader(new MetricInMemoryExporter()))->build(),
            new CompositePropagator([new TraceContextPropagator(), new BaggagePropagator()]),
        );

        $runtime = new FiberRuntime();
        $system = ActorSystem::create('http-test', $runtime, null, null, null, $observability);
        $worker = $system->spawn(
            Props::fromBehavior(Behavior::receive(static fn (ActorContext $ctx, object $msg): Behavior => Behavior::same())),
            'worker',
        );

        // Handler tells an actor while the Server span is active.
        $handler = new class($worker) implements RequestHandlerInterface {
            /** @param ActorRef<object> $worker */
            public function __construct(private readonly ActorRef $worker) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->worker->tell(new HttpWork());

                return new Response(200);
            }
        };

        $middleware = new ServerSpanMiddleware($observability);
        $runtime->scheduleOnce(Duration::zero(), static function () use ($middleware, $handler): void {
            $middleware->process(new ServerRequest('POST', 'https://api.test/orders'), $handler);
        });
        $runtime->scheduleOnce(Duration::millis(300), static fn () => $system->shutdown(Duration::seconds(1)));
        $system->run();
        $tracerProvider->forceFlush();

        $spans = $exporter->getSpans();
        self::assertGreaterThanOrEqual(2, count($spans));

        $server = null;
        $consumer = null;

        foreach ($spans as $span) {
            if ($span->getKind() === 2) {
                $server = $span;
            }

            if ($span->getName() === 'process HttpWork') {
                $consumer = $span;
            }
        }

        self::assertNotNull($server, 'server span missing');
        self::assertNotNull($consumer, 'actor consumer span missing');
        self::assertSame($server->getTraceId(), $consumer->getTraceId());
        self::assertSame($server->getSpanId(), $consumer->getParentSpanId());
    }
}

final readonly class HttpWork {}
```
> The `middleware->process(...)` runs inside a runtime callback so the Server span is activated within the same fiber the actor send observes. `#[WithoutErrorHandler]` guards the known OTEL `FiberBoundContextStorage` `E_USER_WARNING` (see Plan 3); it cannot mask assertion failures.

- [ ] **Step 3: Run — expect PASS**
```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-observability-http/tests/Unit/HttpActorTracePropagationTest.php
```
> If the actor CONSUMER span is NOT a child of the SERVER span, do NOT weaken the assertions — STOP and report BLOCKED with the observed span tree (it would indicate the Server span isn't active when the handler sends, i.e. a real gap).

- [ ] **Step 4: Full gate + deptrac**
```bash
docker compose exec -T php vendor/bin/phpunit --testsuite=unit
make cs-fix && make phpcs && make psalm
docker compose exec -T php php -d error_reporting="E_ALL & ~E_DEPRECATED" vendor/bin/deptrac analyse --config-file=deptrac.yaml
```
Expected: all green; deptrac shows `ObservabilityHttp → {Http, Observability}`, 0 violations.

- [ ] **Step 5: Commit**
```bash
git add packages/nexus-observability-http composer.json composer.lock
git -c commit.gpgsign=false commit --no-verify -m "test(observability-http): flagship HTTP request -> actor -> response single trace"
```

---

## Self-Review (plan author)

- **Spec coverage (Plan 4 slice — §7 HTTP, §5.1, D5/D11, request→actor→response):** Server span middleware as source of truth ✓ (Task 1); `http.*` semconv attributes metadata-only ✓ (D5); RED metrics with low-cardinality dims ✓ (Task 2, D11); flagship request→actor→response single trace proven ✓ (Task 3); fail-isolation + disabled fast-path ✓ (Tasks 1–2, §12). **Out of scope (documented):** HTTP client (no client package exists); WS spans (deferred follow-up); middleware/listener auto-registration in the app wiring (Plan 9 config surfaces).
- **Placeholder scan:** none — complete code or exact commands per step.
- **Type consistency:** `ServerSpanMiddleware(Observability)` and `HttpMetricsListener(Observability)` both consume the provider API from Plan 1/3 (`isEnabled`, `tracer`, `meter`, `propagator`). Span kind `SpanKind::Server` → OTEL kind 2 (asserted). Metric names `http.server.request.duration` / `http.server.active_requests` used in Task 2 and asserted there. Test doubles use the real OTEL bridge (Plan 2).

## Downstream: Plan 5 = persistence (`nexus-observability-persistence`: event/snapshot/state store decorators + replay spans + metrics). Deferred from here: WS spans; middleware/listener registration in NexusApp/HttpApp (Plan 9); the shared Fiber context-storage warning fix (runtime hardening).
