---
sidebar_position: 6
title: Custom Instrumentation
---

# Custom Instrumentation

Inside any actor handler, three observability access points are available through `ActorContext`:

1. **`$ctx->tracer()`** → `\OpenTelemetry\API\Trace\TracerInterface` — create child spans for sub-operations
2. **`$ctx->meter()`** → `\OpenTelemetry\API\Metrics\MeterInterface` — create custom counters, histograms, and gauges
3. **`$ctx->currentSpan()`** → `\OpenTelemetry\API\Trace\SpanInterface` — the span for the message currently being processed

All three return OTel no-op objects when observability is not wired in. No guard is needed.

## Complete example

```php title="order-actor.php" verify:lint-only
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use OpenTelemetry\API\Trace\StatusCode;

$behavior = Behavior::receive(static function (ActorContext $ctx, object $msg): Behavior {
    // 1. Access the current message span (automatically created by Nexus).
    //    Add domain events or attributes to it directly.
    $span = $ctx->currentSpan();
    $span->addEvent('handler.started', ['message.type' => $msg::class]);

    // 2. Create a child span for an expensive sub-operation.
    $tracer = $ctx->tracer();
    $childSpan = $tracer->spanBuilder('validate-order')->startSpan();
    $scope = $childSpan->activate();

    try {
        // ... expensive operation ...
        $childSpan->setAttribute('validation.result', 'passed');
        $childSpan->setStatus(StatusCode::STATUS_OK);
    } catch (\Throwable $e) {
        $childSpan->recordException($e);
        $childSpan->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
        throw $e;
    } finally {
        $scope->detach();
        $childSpan->end();
    }

    // 3. Record a custom metric counter.
    $meter = $ctx->meter();
    $counter = $meter->createCounter(
        'order.validations',
        '{validation}',
        'Number of order validations performed',
    );
    $counter->add(1, ['order.type' => 'standard']);

    return Behavior::same();
});
```

### Why `$scope->detach()` before `$childSpan->end()`

OTel context is a stack. `$childSpan->activate()` pushes the child span as the active context; `$scope->detach()` pops it. Always detach before ending the span, and always do both in a `finally` block so a thrown exception does not leave a dangling context entry.

## Creating instruments once, not per-message

Creating a counter or histogram inside the handler closure is safe — the OTel SDK caches instruments by name and returns the same instance on repeated calls. For hot paths, store the instrument in a closure variable to avoid the lookup:

```php title="cached-instrument.php" verify:lint-only
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use OpenTelemetry\API\Metrics\CounterInterface;

$counter = null;

$behavior = Behavior::receive(static function (ActorContext $ctx, object $msg) use (&$counter): Behavior {
    if ($counter === null) {
        $counter = $ctx->meter()->createCounter(
            'order.validations',
            '{validation}',
            'Number of order validations performed',
        );
    }

    $counter->add(1, ['order.type' => 'standard']);

    return Behavior::same();
});
```

:::note
`CounterInterface` is imported from `OpenTelemetry\API\Metrics\CounterInterface`. The `use (&$counter)` closure capture by reference is intentional here — it is the variable holding the instrument that is mutated (from `null` to the counter), not the counter itself.
:::

## Zero cost when disabled

When `withObservability()` is NOT called on `NexusApp`, all three context methods return OTel SDK no-op objects:

- `$ctx->tracer()` → `\OpenTelemetry\API\Trace\NoopTracer`
- `$ctx->meter()` → `\OpenTelemetry\API\Metrics\Noop\NoopMeter`
- `$ctx->currentSpan()` → `\OpenTelemetry\API\Trace\NonRecordingSpan`

These objects accept all method calls and discard data. PHP's JIT compiler eliminates them. There are no memory allocations, no lock contention, and no overhead in the actor processing path.

## Outside actors

If you need tracing or metrics in a non-actor class (e.g., a repository or a domain service), inject the `Observability` provider via PSR-11 DI:

```php title="outside-actor.php" verify:lint-only
use Monadial\Nexus\Observability\Observability;

final class OrderRepository
{
    public function __construct(private readonly Observability $observability) {}

    public function save(object $order): void
    {
        $tracer = $this->observability->tracer();
        $meter = $this->observability->meter();
        // use $tracer and $meter as needed
    }
}
```

`tracer()` and `meter()` return no-op objects when observability is disabled, so this pattern is safe with or without observability wired in.
