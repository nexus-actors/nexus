---
title: Envelope
sidebar_position: 15
related:
  - core-concepts/actors
  - core-concepts/mailboxes
  - reference/classes/actor-ref
  - reference/classes/mailbox-config
---

# Envelope

Immutable message wrapper that carries a user message, routing metadata, and distributed-tracing IDs through the mailbox.

## What it does

Every message sent via `ActorRef::tell()` is wrapped in an `Envelope` before it enters the target actor's mailbox. The envelope attaches the information the `ActorCell` needs to deliver and trace the message: who sent it, who should receive it, and a set of IDs for distributed tracing.

The three IDs follow the [W3C Trace Context](https://www.w3.org/TR/trace-context/) conventions:

- **`requestId`** — unique ID for this specific message; generated automatically by `Envelope::of()`.
- **`correlationId`** — groups all messages that belong to the same logical operation; defaults to `requestId` on the first message and is propagated through reply chains.
- **`causationId`** — the `requestId` of the message that triggered this one; enables causal fan-out tracing.

The optional `metadata` map carries arbitrary string key/value pairs (for example, an HTTP trace header or a tenant ID) through the message graph without requiring all message types to carry those fields explicitly.

You rarely construct `Envelope` directly in application code. It surfaces when building custom `EventStore` adapters, middleware, or serialization layers where you need to inspect or forward routing context.

## Example

```php title="src/Middleware/TracingMiddleware.php"
use Monadial\Nexus\Core\Mailbox\Envelope;

// The runtime creates envelopes automatically — no manual construction needed
// in typical actor code. Use withMetadata() to attach trace headers:

// Inside a custom transport or interceptor:
$incoming = Envelope::of($message, $senderPath, $targetPath);

// Propagate trace context into a downstream message:
$downstream = Envelope::of($childMessage, $senderPath, $childPath)
    ->withCorrelationId($incoming->correlationId)
    ->withCausationId($incoming->requestId)
    ->withMetadata([
        'tenant-id'    => $incoming->metadata['tenant-id'] ?? '',
        'x-request-id' => $incoming->requestId,
    ]);

// Read routing fields in a custom EventStore adapter:
$persistenceEngine->persist($id, new EventEnvelope(
    payload:      $envelope->message,
    sequenceNr:   $nextSeq,
    writerId:     $system->writerId(),
    correlationId: $envelope->correlationId,
));
```

## Key properties

| Property | Type | Description |
|---|---|---|
| `$message` | `object` | The user-supplied message payload |
| `$sender` | `ActorPath` | Path of the sending actor |
| `$target` | `ActorPath` | Path of the receiving actor |
| `$requestId` | `string` | Unique ID for this message (hex-encoded 16 bytes) |
| `$correlationId` | `string` | Logical operation group ID; defaults to `requestId` |
| `$causationId` | `string` | `requestId` of the triggering message; defaults to `requestId` |
| `$senderRef` | `ActorRef\|null` | Live reference to the sender, if available |
| `$metadata` | `array<string, string>` | Arbitrary string key/value pairs |

## Key methods

- `Envelope::of(object $message, ActorPath $sender, ActorPath $target): self` — create a new envelope with auto-generated IDs and empty metadata.
- `->withSenderRef(ActorRef $senderRef): self` — attach a live sender reference so the receiver can reply.
- `->withMetadata(array $metadata): self` — replace the metadata map; returns a new envelope.
- `->withCorrelationId(string $correlationId): self` — propagate a correlation ID across a message chain.
- `->withCausationId(string $causationId): self` — record the `requestId` of the triggering message.
- `->withSender(ActorPath $sender): self` — override the sender path (used by worker-pool transport when re-routing).
- `->withRequestId(string $requestId): self` — override the request ID (rarely needed outside low-level adapters).

## Full API reference

[Full method list and class hierarchy](https://api.nexusactors.com/classes/Monadial-Nexus-Core-Mailbox-Envelope.html)

## See also

- [Actors concept](../../core-concepts/actors) — how `ActorRef::tell()` wraps messages into envelopes before dispatch
- [Mailboxes concept](../../core-concepts/mailboxes) — how envelopes are queued, delivered, and processed
- [ActorRef](actor-ref) — the handle whose `tell()` method creates and enqueues envelopes
- [MailboxConfig](mailbox-config) — controls how many envelopes a mailbox buffers and what happens when it fills up
