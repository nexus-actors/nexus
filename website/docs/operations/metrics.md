---
title: Metrics
related:
  - operations/observability
  - operations/graceful-shutdown
  - operations/runbook
---

# Metrics

Nexus does not ship a Prometheus endpoint or a metrics registry. It accepts a PSR-14 `EventDispatcherInterface` at boot and fires events as actors start, stop, fail, and process messages — your application code subscribes to those events and publishes counters however it likes.

## What Nexus fires

`ActorSystem::create()` accepts an optional `EventDispatcherInterface`. If you pass one, Nexus dispatches events to it. If you pass `null`, the default `NullDispatcher` is used and nothing is emitted.

### Core actor system events

The actor cell dispatches no structured events today — lifecycle transitions (start, stop, failure) are delivered as `Signal` values to the actor's own `onSignal()` handler, not to the PSR-14 dispatcher. Use the signal handler to emit your own metrics:

```php title="src/Actors/InstrumentedActor.php"
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Lifecycle\PreStart;
use Monadial\Nexus\Core\Lifecycle\PostStop;

$behavior = Behavior::receive(
    static fn($ctx, $msg) => Behavior::same(),
)->onSignal(static function ($ctx, $signal) use ($metrics): Behavior {
    if ($signal instanceof PreStart) {
        $metrics->increment('actor.started');
    }

    if ($signal instanceof PostStop) {
        $metrics->increment('actor.stopped');
    }

    return Behavior::same();
});
```

### Persistence metrics

`nexus-persistence` does not fire PSR-14 events. Persistence metrics — events persisted, recovery duration, snapshot taken — must be tracked inside the actor itself by wrapping the effect chain with a side-effect callback:

```php title="src/Actors/OrderActor.php"
use Monadial\Nexus\Persistence\EventSourced\Effect;

// Inside the command handler:
return Effect::persist($orderPlaced)
    ->thenRun(static function ($state) use ($metrics): void {
        $metrics->increment('persistence.events_persisted_total');
    });
```

## Prometheus exporter recipe

Nexus has no built-in Prometheus integration. The recipe below is entirely application code — nothing is part of the framework.

**Step 1 — Install a Prometheus client:**

```bash title="terminal"
composer require promphp/prometheus_client_php
```

**Step 2 — Wire a listener at boot:**

```php title="src/Bootstrap/MetricsBootstrap.php"
use Prometheus\CollectorRegistry;
use Prometheus\Storage\InMemory;
use Symfony\Component\EventDispatcher\EventDispatcher;

$registry  = new CollectorRegistry(new InMemory());
$counter   = $registry->getOrRegisterCounter(
    'nexus', 'messages_processed_total', 'Messages processed', ['actor'],
);
$dispatcher = new EventDispatcher();

$system = ActorSystem::create(
    'my-app',
    new SwooleRuntime(),
    eventDispatcher: $dispatcher,
);
```

**Step 3 — Expose `/metrics` via a route:**

```php title="src/Http/MetricsController.php"
use Prometheus\RenderTextFormat;

$app->get('/metrics', static function () use ($registry): Response {
    $renderer = new RenderTextFormat();
    $output   = $renderer->render($registry->getMetricFamilySamples());

    return new Response(200, ['Content-Type' => RenderTextFormat::MIME_TYPE], $output);
});
```

## What to instrument

| Signal / event | Suggested counter / gauge |
|---|---|
| `PreStart` | `actor.started_total` |
| `PostStop` | `actor.stopped_total` |
| `ChildFailed` | `actor.failures_total{actor, exception}` |
| `Terminated` (death watch) | `actor.terminated_total` |
| `DeadLetter` received | `actor.dead_letters_total` |
| `Effect::persist()->thenRun()` | `persistence.events_persisted_total` |
| Mailbox overflow (catch in handler) | `mailbox.overflow_total{actor}` |

## Honest assessment

The PSR-14 hook is the correct extension point, but the existing event surface is limited: mailbox metrics require in-handler counters, and there is no per-actor message-rate counter emitted by the framework. Contributions to `nexus-core` that add structured events are welcome; see [Contributing](../contributing/development.md).

## See also

- [Observability](./observability.md)
- [Graceful shutdown](./graceful-shutdown.md)
- [On-call runbook](./runbook.md)
