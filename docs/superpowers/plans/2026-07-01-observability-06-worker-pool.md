# Observability — Plan 6: Worker-Pool (`nexus-observability-worker-pool`) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Instrument cross-worker message transport — a `WorkerTransport` decorator that opens a Producer span per cross-worker send, **injects the trace context into the `Envelope::metadata` so the trace survives the worker-thread boundary** (WorkerActorRef sends envelopes directly without injecting), and records transport metrics — driven by the injected `Observability` provider (no-op default), fail-isolated.

**Architecture:** `TracingWorkerTransport` decorates any `WorkerTransport`. On `send()` it starts a Producer span (child of the ambient context), injects that span's context into the outgoing envelope's metadata via the propagator, records send metrics, and delegates to the inner transport; the receiving worker's `ActorCell::processMessage` (Plan 3) extracts the context and opens its Consumer span as a child — so one trace spans the worker boundary. `listen`/`close`/`stop`/`isStopped` delegate unchanged. Disabled → pure pass-through.

**Scope note:** The receive side needs no new span — the actor already opens the Consumer span from `Envelope::metadata` (Plan 3). This plan is send-side injection + transport metrics only. `ConsistentHashRing`/`WorkerDirectory` routing spans are out of scope (the target worker id is captured as a span attribute on send).

**Tech Stack:** PHP 8.5.7, `nexus-observability` (+ `nexus-observability-otel` & `open-telemetry/sdk` dev-deps for tests, `nexus-worker-pool` for the interface + in-memory transport, `nexus-core` for `Envelope`), PHPUnit 13, Psalm L1, PHPCS, Deptrac, Docker.

## Global Constraints

- **Docker only:** prefix with `docker compose exec -T php`. `composer dump-autoload` after adding classes.
- **Commit policy:** `git -c commit.gpgsign=false commit --no-verify` (GrumPHP hook broken + worktree GPG times out). Before EVERY commit: `make cs-fix && make phpcs && make psalm` (clean) + package suite. `make phpcs` enforces `ReferenceUsedNamesOnly`. `Warning: JIT...` is env noise.
- **NEVER** add `Co-Authored-By: Claude`. **No singletons.**
- **Fail-isolation (§12):** telemetry guarded (`safely()`); the inner `send()` call is NOT guarded — transport errors propagate (record on span first, then rethrow). Disabled fast-path = pure delegation, zero telemetry, no envelope mutation.
- **Attributes (D5/D11):** span attrs `messaging.operation`, `messaging.system`, `nexus.actor.path`, `nexus.worker.target` (metadata only). Metric dims: `nexus.worker.target` only (bounded worker id) — never actor path.
- **Code style:** `declare(strict_types=1);`; `final`; `/** @psalm-api */`; alphabetical imports (+ `use function`); string-keyed arrays **alphabetical**; trailing commas; blank line before control structures; multi-line ternaries; `#[Override]`.
- **Deptrac:** new layer `ObservabilityWorkerPool` may depend only on `Core` (for `Envelope`), `Observability`, `WorkerPool`.
- **`finishSpan` rule (from Plan 5):** end the span in one `safely()` FIRST, then record the histogram in a SEPARATE `safely()`, so a metric failure can never leak a span.
- **Tests:** decorate the real `InMemoryWorkerTransport`; assert the delivered envelope (`getSentTo()`) carries `traceparent`, the Producer span + attributes/metrics are exported (OTEL bridge in-memory), the disabled path injects nothing and emits nothing, and delegation works.

## Verified seams

- `Monadial\Nexus\WorkerPool\Transport\WorkerTransport`: `send(int $targetWorker, Envelope $envelope): void`; `listen(callable $onEnvelope): void`; `close(): void`; `stop(): void`; `isStopped(): bool`.
- `InMemoryWorkerTransport`: records sends; `getSentTo(int $workerId): list<Envelope>`; `receive(Envelope)` invokes the listener; `stop()`/`isStopped()`.
- `Monadial\Nexus\Core\Mailbox\Envelope`: `public array $metadata` (`array<string,string>`), `withMetadata(array): self`, `public ActorPath $target`.
- `Observability`: `isEnabled()`, `tracer()`, `meter()`, `propagator()`, `currentContext()`. `Tracer::startSpan(name, SpanKind, array<string,scalar>, ?Context)`. `ContextPropagator::inject(Context, array &$carrier)`.

---

## File Structure

```
packages/nexus-observability-worker-pool/
  composer.json
  src/
    TracingWorkerTransport.php
  tests/
    Unit/
      TracingWorkerTransportTest.php
```
Shared files modified by Task 1: root `composer.json`, `deptrac.yaml`, `phpunit.xml`.

---

## Task 1: Scaffold + `TracingWorkerTransport`

**Files:**
- Create: `packages/nexus-observability-worker-pool/composer.json`
- Create: `packages/nexus-observability-worker-pool/src/TracingWorkerTransport.php`
- Create: `packages/nexus-observability-worker-pool/tests/Unit/TracingWorkerTransportTest.php`
- Modify: root `composer.json`, `deptrac.yaml`, `phpunit.xml`

**Interfaces:**
- Produces: `final class TracingWorkerTransport implements WorkerTransport` — ctor `(WorkerTransport $inner, Observability $observability)`; `send()` opens a Producer span, injects context into envelope metadata, records `nexus.worker_pool.messages.sent` counter + `nexus.worker_pool.send.duration` histogram, delegates; `listen/close/stop/isStopped` delegate.

- [ ] **Step 1: `packages/nexus-observability-worker-pool/composer.json`**
```json
{
    "name": "nexus-actors/observability-worker-pool",
    "description": "Nexus worker-pool observability — tracing transport decorator that propagates trace context across worker threads and records transport metrics.",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": ">=8.5.7",
        "nexus-actors/core": "dev-main",
        "nexus-actors/observability": "dev-main",
        "nexus-actors/worker-pool": "dev-main"
    },
    "require-dev": {
        "nexus-actors/observability-otel": "dev-main",
        "open-telemetry/sdk": "^1.14",
        "phpunit/phpunit": "^13.0"
    },
    "autoload": {
        "psr-4": {
            "Monadial\\Nexus\\Observability\\WorkerPool\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Monadial\\Nexus\\Observability\\WorkerPool\\Tests\\": "tests/"
        }
    }
}
```
> Verify the worker-pool Packagist name in `packages/nexus-worker-pool/composer.json` (expected `nexus-actors/worker-pool`); match it exactly in `require`.

- [ ] **Step 2: Root `composer.json`** — add to `autoload.psr-4`:
```json
            "Monadial\\Nexus\\Observability\\WorkerPool\\": "packages/nexus-observability-worker-pool/src/",
```
and to `autoload-dev.psr-4`:
```json
            "Monadial\\Nexus\\Observability\\WorkerPool\\Tests\\": "packages/nexus-observability-worker-pool/tests/",
```
Run `docker compose exec -T php composer dump-autoload`.

- [ ] **Step 3: `deptrac.yaml`** — add layer:
```yaml
    - name: ObservabilityWorkerPool
      collectors:
        - type: directory
          value: packages/nexus-observability-worker-pool/src/.*
```
and ruleset:
```yaml
    ObservabilityWorkerPool:
      - Core
      - Observability
      - WorkerPool
```

- [ ] **Step 4: `phpunit.xml`** — add to `<testsuite name="unit">`:
```xml
            <directory>packages/nexus-observability-worker-pool/tests/Unit</directory>
```
and to `<source><include>`:
```xml
            <directory>packages/nexus-observability-worker-pool/src</directory>
```

- [ ] **Step 5: Write the failing test**

`packages/nexus-observability-worker-pool/tests/Unit/TracingWorkerTransportTest.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\WorkerPool\Tests\Unit;

use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Mailbox\Envelope;
use Monadial\Nexus\Observability\Context\BaggagePropagator;
use Monadial\Nexus\Observability\Context\CompositePropagator;
use Monadial\Nexus\Observability\Context\TraceContextPropagator;
use Monadial\Nexus\Observability\NoopObservability;
use Monadial\Nexus\Observability\Otel\OtelObservability;
use Monadial\Nexus\Observability\Trace\SpanKind;
use Monadial\Nexus\Observability\WorkerPool\TracingWorkerTransport;
use Monadial\Nexus\WorkerPool\Transport\InMemoryWorkerTransport;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter as MetricInMemoryExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_key_exists;
use function array_map;

#[CoversClass(TracingWorkerTransport::class)]
final class TracingWorkerTransportTest extends TestCase
{
    #[Test]
    public function sendInjectsContextOpensProducerSpanAndMeters(): void
    {
        $spanExporter = new InMemoryExporter();
        $tracerProvider = new TracerProvider(new SimpleSpanProcessor($spanExporter));
        $metricExporter = new MetricInMemoryExporter();
        $reader = new ExportingReader($metricExporter);
        $observability = new OtelObservability(
            $tracerProvider,
            MeterProvider::builder()->addReader($reader)->build(),
            new CompositePropagator([new TraceContextPropagator(), new BaggagePropagator()]),
        );

        $inner = new InMemoryWorkerTransport();
        $transport = new TracingWorkerTransport($inner, $observability);

        // Send within an active span so there is a context to propagate.
        $outer = $observability->tracer()->startSpan('outer', SpanKind::Internal);
        $envelope = Envelope::of(new WorkerPoolTestMsg(), ActorPath::root(), ActorPath::fromString('/user/target'));
        $transport->send(2, $envelope);
        $outer->end();

        $delivered = $inner->getSentTo(2);
        self::assertCount(1, $delivered);
        self::assertTrue(array_key_exists('traceparent', $delivered[0]->metadata));

        $tracerProvider->forceFlush();
        $spans = $spanExporter->getSpans();
        $producer = null;

        foreach ($spans as $span) {
            if ($span->getName() === 'worker.send') {
                $producer = $span;
            }
        }

        self::assertNotNull($producer);
        self::assertSame(3, $producer->getKind()); // PRODUCER
        self::assertSame(2, $producer->getAttributes()->get('nexus.worker.target'));

        $reader->collect();
        $metricNames = array_map(static fn ($metric): string => $metric->name, $metricExporter->collect());
        self::assertContains('nexus.worker_pool.messages.sent', $metricNames);
        self::assertContains('nexus.worker_pool.send.duration', $metricNames);
    }

    #[Test]
    public function disabledObservabilityDelegatesWithoutInjectionOrSpans(): void
    {
        $spanExporter = new InMemoryExporter();
        $tracerProvider = new TracerProvider(new SimpleSpanProcessor($spanExporter));
        $inner = new InMemoryWorkerTransport();
        $transport = new TracingWorkerTransport($inner, new NoopObservability());

        $envelope = Envelope::of(new WorkerPoolTestMsg(), ActorPath::root(), ActorPath::fromString('/user/target'));
        $transport->send(1, $envelope);

        $delivered = $inner->getSentTo(1);
        self::assertCount(1, $delivered);
        self::assertArrayNotHasKey('traceparent', $delivered[0]->metadata);

        $tracerProvider->forceFlush();
        self::assertCount(0, $spanExporter->getSpans());
    }

    #[Test]
    public function delegatesListenAndStop(): void
    {
        $inner = new InMemoryWorkerTransport();
        $transport = new TracingWorkerTransport($inner, new NoopObservability());

        $received = [];
        $transport->listen(static function (Envelope $envelope) use (&$received): void {
            $received[] = $envelope;
        });
        $inner->receive(Envelope::of(new WorkerPoolTestMsg(), ActorPath::root(), ActorPath::fromString('/user/x')));
        self::assertCount(1, $received);

        self::assertFalse($transport->isStopped());
        $transport->stop();
        self::assertTrue($transport->isStopped());
    }
}

final readonly class WorkerPoolTestMsg {}
```

- [ ] **Step 6: Run — expect FAIL** (`TracingWorkerTransport` not found):
`docker compose exec -T php vendor/bin/phpunit packages/nexus-observability-worker-pool/tests/Unit/TracingWorkerTransportTest.php`

- [ ] **Step 7: Create `TracingWorkerTransport`**

`packages/nexus-observability-worker-pool/src/TracingWorkerTransport.php`:
```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\WorkerPool;

use Monadial\Nexus\Core\Mailbox\Envelope;
use Monadial\Nexus\Observability\Metric\Counter;
use Monadial\Nexus\Observability\Metric\Histogram;
use Monadial\Nexus\Observability\Observability;
use Monadial\Nexus\Observability\Trace\Span;
use Monadial\Nexus\Observability\Trace\SpanKind;
use Monadial\Nexus\Observability\Trace\StatusCode;
use Monadial\Nexus\WorkerPool\Transport\WorkerTransport;
use Override;
use Throwable;

use function hrtime;

/**
 * @psalm-api
 *
 * Tracing decorator for a {@see WorkerTransport}. On send it opens a Producer
 * span, injects that span's context into the envelope's metadata so the trace
 * survives the worker-thread boundary (the receiving actor opens a Consumer
 * span from the metadata), and records transport metrics. Transport errors
 * propagate; telemetry never breaks a send; disabled → pure delegation.
 */
final class TracingWorkerTransport implements WorkerTransport
{
    private ?Counter $messagesSent = null;

    private ?Histogram $sendDuration = null;

    public function __construct(
        private readonly WorkerTransport $inner,
        private readonly Observability $observability,
    ) {}

    #[Override]
    public function send(int $targetWorker, Envelope $envelope): void
    {
        if (!$this->observability->isEnabled()) {
            $this->inner->send($targetWorker, $envelope);

            return;
        }

        $span = $this->startSpan($targetWorker, $envelope);
        $envelope = $this->inject($envelope);
        $start = hrtime(true);

        try {
            $this->inner->send($targetWorker, $envelope);
            $this->safely(fn (): mixed => $this->messagesSentCounter()->add(1, ['nexus.worker.target' => $targetWorker]));
        } catch (Throwable $e) {
            $this->recordError($span, $e);

            throw $e;
        } finally {
            $this->finishSpan($span, $targetWorker, $start);
        }
    }

    #[Override]
    public function listen(callable $onEnvelope): void
    {
        $this->inner->listen($onEnvelope);
    }

    #[Override]
    public function close(): void
    {
        $this->inner->close();
    }

    #[Override]
    public function stop(): void
    {
        $this->inner->stop();
    }

    #[Override]
    public function isStopped(): bool
    {
        return $this->inner->isStopped();
    }

    private function startSpan(int $targetWorker, Envelope $envelope): ?Span
    {
        try {
            return $this->observability->tracer()->startSpan(
                'worker.send',
                SpanKind::Producer,
                [
                    'messaging.operation' => 'send',
                    'messaging.system' => 'nexus',
                    'nexus.actor.path' => $envelope->target->toString(),
                    'nexus.worker.target' => $targetWorker,
                ],
            );
        } catch (Throwable) {
            return null;
        }
    }

    private function inject(Envelope $envelope): Envelope
    {
        try {
            $carrier = $envelope->metadata;
            $this->observability->propagator()->inject($this->observability->currentContext(), $carrier);

            return $carrier === $envelope->metadata
                ? $envelope
                : $envelope->withMetadata($carrier);
        } catch (Throwable) {
            return $envelope;
        }
    }

    private function finishSpan(?Span $span, int $targetWorker, int $startNanos): void
    {
        $this->safely(static fn (): mixed => $span?->end());
        $this->safely(function () use ($targetWorker, $startNanos): void {
            $this->sendDurationHistogram()->record(
                (hrtime(true) - $startNanos) / 1_000_000_000,
                ['nexus.worker.target' => $targetWorker],
            );
        });
    }

    private function recordError(?Span $span, Throwable $e): void
    {
        $this->safely(static function () use ($span, $e): void {
            $span?->recordException($e);
            $span?->setStatus(StatusCode::Error, $e->getMessage());
        });
    }

    private function messagesSentCounter(): Counter
    {
        return $this->messagesSent ??= $this->observability->meter()->counter(
            'nexus.worker_pool.messages.sent',
            '{message}',
            'Messages sent across worker threads',
        );
    }

    private function sendDurationHistogram(): Histogram
    {
        return $this->sendDuration ??= $this->observability->meter()->histogram(
            'nexus.worker_pool.send.duration',
            's',
            'Duration of cross-worker transport sends',
        );
    }

    /**
     * @param callable(): mixed $fn
     */
    private function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable) {
            // Telemetry must never break transport.
        }
    }
}
```
> The `inject()` note: `$this->observability->currentContext()` returns the ambient context (the Producer span was just started + activated by the OTEL bridge, so it is now current) — injecting it makes the receiving Consumer span a child of the Producer span. On the disabled path this method is never reached. The `$carrier === $envelope->metadata` check avoids a needless clone when nothing was injected (e.g. root context).

- [ ] **Step 8: Run — expect PASS.** Then full gate + deptrac:
```bash
docker compose exec -T php vendor/bin/phpunit packages/nexus-observability-worker-pool/tests/Unit
docker compose exec -T php vendor/bin/phpunit --testsuite=unit
make cs-fix && make phpcs && make psalm
docker compose exec -T php php -d error_reporting="E_ALL & ~E_DEPRECATED" vendor/bin/deptrac analyse --config-file=deptrac.yaml
```
Expected: all green; deptrac shows `ObservabilityWorkerPool → {Core, Observability, WorkerPool}`, 0 violations.

- [ ] **Step 9: Commit**
```bash
git add packages/nexus-observability-worker-pool composer.json composer.lock deptrac.yaml phpunit.xml
git -c commit.gpgsign=false commit --no-verify -m "feat(observability-worker-pool): TracingWorkerTransport (cross-worker context propagation + metrics)"
```

---

## Self-Review (plan author)

- **Spec coverage (Plan 6 slice — §7 worker-pool, §6 propagation, §8 metrics, D5/D11, §12):** transport decorator ✓; **cross-worker context injection** into `Envelope::metadata` (Producer span) so the trace survives the thread boundary ✓ (§6 — the receive-side Consumer span comes from Plan 3); transport metrics (messages sent counter + send duration histogram) with low-cardinality dim `nexus.worker.target` ✓ (D11); metadata-only span attrs ✓ (D5); fail-isolation + disabled fast-path + errors propagate ✓ (§12); split-safely `finishSpan` (end first) ✓. **Out of scope (documented):** hash-ring/`WorkerDirectory` routing spans (target captured as attribute); Swoole `ThreadQueueTransport` wiring (the decorator wraps any `WorkerTransport`; wiring is Plan 9); cluster `ClusterTransport` (analogous, future).
- **Placeholder scan:** none — complete code or exact commands; one Packagist-name verification flagged.
- **Type consistency:** `TracingWorkerTransport(WorkerTransport, Observability)` implements the full `WorkerTransport` interface (send/listen/close/stop/isStopped). Span kind `SpanKind::Producer` → OTEL 3 (asserted). Metric names `nexus.worker_pool.messages.sent`/`nexus.worker_pool.send.duration` used in code + asserted. `finishSpan` mirrors the Plan-5 split-safely pattern.

## Downstream: Plan 7 = Doctrine (`nexus-observability-doctrine`: DBAL pool + SQL spans via a DBAL Middleware, ORM EM-pool + transaction spans, entity actor-repository `EntityRefFactory` spans/metrics). Deferred from here: cluster transport tracing; ThreadQueueTransport wiring (Plan 9).
