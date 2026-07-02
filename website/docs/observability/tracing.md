---
sidebar_position: 4
title: Tracing
---

# Tracing

Every actor message processed by Nexus automatically produces an OTel span. You do not need to add any instrumentation to your actor code to get basic traces.

## Automatic actor spans

When an actor dequeues an envelope from its mailbox and begins processing it, Nexus starts a span named `actor.message`. The span ends when the handler returns (or throws). The following attributes are set on every actor span:

| Attribute | Example | Description |
|---|---|---|
| `actor.path` | `nexus://my-app/user/orders` | Full actor path |
| `actor.message_type` | `App\Order\Command\PlaceOrder` | FQCN of the message class |
| `actor.runtime` | `fiber` or `swoole` | Runtime that executed the handler |

If the handler throws an exception, the span status is set to `ERROR` and the exception is recorded on the span via `recordException()`.

## Adding attributes to the current span

From inside any actor handler, call `$ctx->currentSpan()` to access the span for the message being processed:

```php title="orders-actor.php" verify:lint-only
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;

$behavior = Behavior::receive(static function (ActorContext $ctx, object $msg): Behavior {
    // Add domain-specific attributes to the automatic actor span
    $ctx->currentSpan()->setAttribute('order.id', 'ord-123');
    $ctx->currentSpan()->addEvent('validation.passed');

    return Behavior::same();
});
```

`$ctx->currentSpan()` never returns `null`. When observability is disabled it returns the OTel SDK's no-op span, so your code is safe with no guard needed.

:::note
Only add **low-cardinality** attributes to spans. Good candidates: `order.status`, `payment.method`, `actor.shard`. Avoid user IDs, request IDs, or other high-cardinality values as span attributes — use baggage or log events instead. High-cardinality span attributes cause index bloat in trace backends.
:::

## Context propagation

When one actor sends a message to another, the current trace context is automatically injected into `Envelope::metadata` as W3C Trace Context headers (`traceparent` and `tracestate`). The receiving actor extracts the context and creates a child span linked to the original trace.

This propagation is handled by the Nexus runtime layer — actor code neither injects nor extracts context. The same mechanism works across:

- **Local actors** — in the same process and thread
- **Worker-pool actors** — cross-thread via `TracingWorkerTransport` (see [Full Wiring Guide](./wiring.md))
- **Future cluster actors** — remote nodes via the cluster transport layer

## HTTP integration

When `nexus-observability-http` is installed and `ServerSpanMiddleware` is registered, incoming HTTP requests start a server span by extracting W3C Trace Context headers from the request. When the HTTP handler calls `ask()` on an actor, the server span's context flows into the envelope automatically. The server span's HTTP status code attribute is set after the handler returns.

This means a Jaeger or Tempo trace for an HTTP request shows:

```text
HTTP POST /orders  [http.server]  → duration: 45ms
└── orders actor: Handle(PlaceOrder)  [nexus.actor.message]  → 38ms
    └── persistence: persist event  [nexus.persistence.event]  → 12ms
        └── SQL INSERT  [db.query]  → 4ms
```

## Span lifecycle

| Event | Action |
|---|---|
| Actor dequeues envelope | Span starts; trace context extracted from `Envelope::metadata` |
| Handler returns `Behavior::same()` / etc. | Span ends with status `OK` |
| Handler throws `\Throwable` | Exception recorded on span; status set to `ERROR`; span ends |
| `Behavior::stopped()` returned | Span ends with status `OK`; `PostStop` signal is a separate span |

## Child spans for sub-operations

To trace an expensive sub-operation inside a handler, use `$ctx->tracer()` to create a child span explicitly. See [Custom Instrumentation](./custom-instrumentation.md) for a complete example.
