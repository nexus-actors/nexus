---
sidebar_position: 6
title: Custom Instrumentation
---

# Custom Instrumentation

Inside any actor handler, three observability access points are available through `ActorContext`:

1. **`$ctx->tracer()`** → `Monadial\Nexus\Observability\Trace\Tracer` — create child spans for sub-operations
2. **`$ctx->meter()`** → `Monadial\Nexus\Observability\Metric\Meter` — create custom counters, histograms, and gauges
3. **`$ctx->currentSpan()`** → `Monadial\Nexus\Observability\Trace\Span` — the span for the message currently being processed

These are Nexus's vendor-neutral telemetry interfaces from `nexus-observability` — actor code never touches OTel types directly. All three return built-in no-op objects when observability is not wired in. No guard is needed.

## Complete example

```php title="order-actor.php" verify:lint-only
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Observability\Trace\StatusCode;

$behavior = Behavior::receive(static function (ActorContext $ctx, object $msg): Behavior {
    // 1. Access the current message span (automatically created by Nexus).
    //    Add domain events or attributes to it directly.
    $span = $ctx->currentSpan();
    $span->addEvent('handler.started', ['message.type' => $msg::class]);

    // 2. Create a child span for an expensive sub-operation.
    $tracer = $ctx->tracer();
    $childSpan = $tracer->startSpan('validate-order');

    try {
        // ... expensive operation ...
        $childSpan->setAttribute('validation.result', 'passed');
        $childSpan->setStatus(StatusCode::Ok);
    } catch (\Throwable $e) {
        $childSpan->recordException($e);
        $childSpan->setStatus(StatusCode::Error, $e->getMessage());
        throw $e;
    } finally {
        $childSpan->end();
    }

    // 3. Record a custom metric counter.
    $meter = $ctx->meter();
    $counter = $meter->counter(
        'order.validations',
        '{validation}',
        'Number of order validations performed',
    );
    $counter->add(1, ['order.type' => 'standard']);

    return Behavior::same();
});
```

### Why `$childSpan->end()` goes in a `finally` block

`startSpan()` activates the new span so any spans started while it is open become its children; `end()` closes the span and restores the previous active context. Always call `end()` in a `finally` block so a thrown exception does not leave a dangling active span.

## Creating instruments once, not per-message

Creating a counter or histogram inside the handler closure is safe — the underlying SDK caches instruments by name and returns the same instance on repeated calls. For hot paths, store the instrument in a closure variable to avoid the lookup:

```php title="cached-instrument.php" verify:lint-only
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Observability\Metric\Counter;

$counter = null;

$behavior = Behavior::receive(static function (ActorContext $ctx, object $msg) use (&$counter): Behavior {
    if ($counter === null) {
        $counter = $ctx->meter()->counter(
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
`Counter` is imported from `Monadial\Nexus\Observability\Metric\Counter`. The `use (&$counter)` closure capture by reference is intentional here — it is the variable holding the instrument that is mutated (from `null` to the counter), not the counter itself.
:::

## Zero cost when disabled

When `withObservability()` is NOT called on `NexusApp`, all three context methods return Nexus's built-in no-op objects from `nexus-observability`:

- `$ctx->tracer()` → `Monadial\Nexus\Observability\Trace\NoopTracer`
- `$ctx->meter()` → `Monadial\Nexus\Observability\Metric\NoopMeter`
- `$ctx->currentSpan()` → `Monadial\Nexus\Observability\Trace\NoopSpan`

These objects accept all method calls and discard data — no OTel classes are ever loaded. There are no memory allocations, no lock contention, and no overhead in the actor processing path.

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
