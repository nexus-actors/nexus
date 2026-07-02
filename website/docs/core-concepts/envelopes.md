---
title: Envelopes
related:
  - core-concepts/mailboxes
  - core-concepts/ask-pattern
  - reference/classes/actor-ref
  - guides/ask-vs-tell
---

# Envelopes

Every message traveling through the actor system is wrapped in an `Envelope` before it enters a mailbox. The envelope adds routing metadata and distributed-tracing identifiers so message flows are traceable across actor boundaries and across process boundaries in the worker pool.

## The design

When you call `tell()` or `ask()`, the runtime constructs an `Envelope` around your message. The envelope is immutable — a `final readonly class` — and carries:

- **`message`** — the original user message object.
- **`sender`** and **`target`** — `ActorPath` values identifying the two parties. These survive serialization, unlike live `ActorRef` objects.
- **`senderRef`** — optional live `ActorRef` to the sender. Present when the runtime can supply it; `null` for anonymous sends.
- **`requestId`** — a unique ID for this specific `tell()` or `ask()` call.
- **`correlationId`** — ties together all messages belonging to the same logical request chain (fan-out messages share the correlation ID of the originating message).
- **`causationId`** — identifies the direct parent message that triggered this one, enabling a causal DAG for distributed tracing.
- **`metadata`** — `array<string, string>` for arbitrary key-value pairs (trace headers, tenant IDs, etc.).

When `Envelope::of()` creates a new envelope from scratch, `requestId`, `correlationId`, and `causationId` all start as the same generated ID. Child messages produced in response to a parent message should propagate `correlationId` and set `causationId` to the parent's `requestId`.

## Tradeoffs

| You gain | You give up |
|---|---|
| Full message provenance for every in-flight message | One heap allocation per message send |
| Serialization-safe routing (paths, not live refs) | Slightly more payload when crossing process boundaries |
| Pluggable tracing without touching user message classes | — |

## When you encounter it

Most application code never touches `Envelope` directly — `tell()` and `ask()` handle construction. You work with envelopes directly in three situations:

- **Custom transports** — the worker-pool `WorkerTransport` interface passes `Envelope` objects directly across Swoole threads. Implementing a transport means constructing and consuming envelopes.
- **Serialization adapters** — `EnvelopeSerializer` wraps message serialization, and its implementations accept and produce `Envelope` instances.
- **Tracing instrumentation** — intercepting the mailbox layer to stamp `correlationId`/`causationId` headers onto outbound HTTP requests or log entries.

<!-- verify:skip: illustrates direct envelope construction in a transport context that requires a running worker pool -->
```php title="src/Transport/MyCustomTransport.php" verify:skip
<?php

declare(strict_types=1);

use Monadial\Nexus\Core\Mailbox\Envelope;
use Monadial\Nexus\Core\Actor\ActorPath;

// Reconstruct an envelope from wire bytes in a custom transport
$envelope = Envelope::of($message, $senderPath, $targetPath)
    ->withCorrelationId($incomingCorrelationId)
    ->withCausationId($incomingRequestId)
    ->withMetadata(['x-tenant' => $tenantId]);

$transport->send($targetWorkerId, $envelope);
```

## See also

- [Mailboxes](./mailboxes.md) — the queue that envelopes flow through.
- [Ask pattern](./ask-pattern.md) — how `ask()` constructs a temporary `FutureRef` and stamps envelopes with matching IDs.
- [Worker pool scaling](../scaling/overview.md) — where `WorkerTransport` passes envelopes across Swoole threads.
