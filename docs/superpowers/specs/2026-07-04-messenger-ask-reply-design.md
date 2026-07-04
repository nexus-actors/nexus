# Nexus Messenger — Broker Request/Reply (`ask()`) — Design

- **Date:** 2026-07-04
- **Status:** Approved design, ready for implementation plan
- **Package:** `nexus-messenger` (extends the existing bridge; no new packages)
- **Depends on:** the shipped bridge (PR #50): `MessengerActorRef`, `ReceiverActor`, `NexusMessengerSerializer` header mechanism, `BackpressureCapable`, observability stamps/headers.

## 1. Motivation

The v1 bridge deferred `MessengerActorRef::ask()` (it throws `UnsupportedOperationException`). This design adds location-transparent request/reply over any Symfony Messenger transport, completing the scalable distributed-actor story: competing consumers for scale-out, watchdog recycling for lifecycle, and now `$ref->ask($msg, $timeout)->await()` working identically whether the target is a local actor or a service on the other side of a broker.

**Interop is a hard requirement:** a plain symfony/messenger application (no Nexus) must be able to answer asks by following a documented header convention.

## 2. Constraints

- **Messenger is the backbone.** Reply channels are themselves Symfony Messenger transports, built through the user's installed `TransportFactory` chain from DSNs. No raw broker SDK code in this feature (no ext-amqp direct-reply-to, no AWS SDK calls). Broker specifics reduce to DSN templates + transport options (`auto_setup`, TTL/auto-delete).
- `nexus-messenger`'s dependencies do not grow: still core, runtime, serialization, observability, psr/event-dispatcher, symfony/messenger.
- All existing bridge semantics unchanged for `tell()` paths. New params are trailing and optional; existing tests pass unmodified.
- Repo conventions as per the v1 spec (final/readonly, Psalm level 1, style gates, TDD).

## 3. Wire protocol (interop-stable)

Two new headers, carried by stamps exactly like `X-Nexus-Trace-Context`:

| Header | Stamp | Content |
|---|---|---|
| `X-Nexus-Correlation-Id` | `CorrelationIdStamp(string $id)` | UUID v4 generated per ask |
| `X-Nexus-Reply-To` | `ReplyToStamp(string $channel)` | **Logical channel name** — never a DSN |

Replies carry `X-Nexus-Correlation-Id` (copied verbatim) and the reply message body/type through the normal serializer. `X-Nexus-Reply-To` on the wire is a logical name for **security**: the responder resolves it against its configured map of reply senders. A raw DSN from the wire would be an attacker-controlled SSRF/queue-write primitive and is explicitly forbidden — unknown reply-to names are rejected (message rejected + warning log + `nexus.messenger.asks.unroutable_reply_to` counter).

## 4. Asker side

### 4.1 `MessengerActorRef::ask(object $message, Duration $timeout): Future`

Stops throwing. Mirrors `LocalActorRef::ask()`:

1. Create `FutureSlot` via `Runtime::createFutureSlot()`; register it in the `PendingAskRegistry` under a fresh correlation id.
2. Schedule the timeout via `Runtime::scheduleOnce()` → `AskTimeoutException` + registry removal.
3. Publish the request with `CorrelationIdStamp` + `ReplyToStamp($replyChannel->name())` (+ existing source/trace stamps).
4. Return `new Future($slot)`.

`ask()` requires ask support to be configured on the ref (a `ReplyChannel` + registry, wired via `MessengerBridge`); an unconfigured ref keeps throwing `UnsupportedOperationException` with a message pointing at the wiring helper.

### 4.2 `PendingAskRegistry`

- Map correlation id → `FutureSlot` + metadata (created-at, message type).
- **Bounded, fail-fast:** configurable cap (default 10 000). At capacity, `ask()` fails immediately with `AskCapacityExceededException extends NexusException` — no unbounded future accumulation during broker outages.
- `resolve(string $correlationId, object $reply): bool` — first reply wins; late/duplicate/unknown correlations are dropped with a debug log + `nexus.messenger.replies.dropped` counter.
- Timeout path removes the entry before failing the slot.

### 4.3 `ReplyChannel` + lifecycle strategies (user-switchable)

```php
interface ReplyChannelFactory
{
    public function create(): ReplyChannel; // called once per process, lazily on first ask
}

interface ReplyChannel
{
    public function name(): string;                 // logical name advertised in X-Nexus-Reply-To
    public function receiver(): ReceiverInterface;  // consumed by the ReplyConsumer
    public function close(): void;                  // strategy-dependent teardown
}
```

Shipped factory: `TransportReplyChannelFactory` — builds the transport from a **DSN template** through a user-provided `TransportFactoryInterface` (the factory chain from their installed symfony bridge) and the Nexus serializer. Template placeholders: `{instance}` (ULID per process), `{name}` (logical channel name). The lifecycle is a **strategy enum** the user switches:

- `ReplyQueueLifecycle::Ephemeral` (default) — DSN includes `{instance}`; the transport's `auto_setup` creates the queue; broker-side TTL/auto-delete options (passed through DSN/options) expire it after the process dies. `close()` is a no-op.
- `ReplyQueueLifecycle::DeleteOnShutdown` — like Ephemeral, but `close()` runs during `ActorSystem` shutdown and deletes via the transport's `SetupableTransportInterface`/options where supported; TTL remains the crash backstop.
- `ReplyQueueLifecycle::Persistent` — fixed pre-provisioned queue name (no `{instance}`, no auto-setup); operator-managed. Only safe with one asking process per queue — documented loudly (competing consumers on a shared reply queue steal each other's replies).

### 4.4 `ReplyConsumerActor`

One per `ActorSystem`, spawned lazily on first ask by the wiring helper. It reuses the `ReceiverActor` poll loop pointed at `ReplyChannel::receiver()` with a fixed internal router: every reply resolves through `PendingAskRegistry::resolve()` (ack always — a reply is consumed even if late; late replies are logged/counted, not redelivered). Supervised like any receiver.

## 5. Responder side

### 5.1 Nexus responders — zero handler changes

`ReceiverActor` detects `CorrelationIdStamp` + `ReplyToStamp` on an inbound envelope:

1. Resolve the reply-to name via the configured `ReplySenderLocator` (map name → `SenderInterface`; unknown → reject + warn, see §3).
2. Build a core `Envelope` for the target with `senderRef = new MessengerReplyRef($sender, $correlationId, ...)` and deliver via `LocalActorRef::enqueueEnvelope()` (falls back to plain `tell()` for non-local refs, losing reply capability — documented).
3. `MessengerReplyRef implements ActorRef` — `tell($reply)` publishes the reply with `CorrelationIdStamp` copied; `ask()` on it throws; `path()` synthetic `/messenger/reply/<correlation>`. So `$ctx->reply($answer)` works unchanged.

### 5.2 Ask acking — stronger guarantee (process-ack)

For ask-stamped messages only, the broker ack is deferred until the reply has been sent (or the actor failed): `MessengerReplyRef::tell()` triggers the ack callback for its envelope after a successful reply publish. A responder crash mid-processing therefore redelivers the request; combined with first-reply-wins dedup on the asker, the asker never silently loses an answer. Implementation seam: `ReceiverActor` keeps the Messenger envelope un-acked in a pending map keyed by correlation id until the reply ref fires (with a config-bounded pending window; expiry → reject for redelivery). Plain `tell()` messages keep ack-on-accepted-enqueue exactly as today.

### 5.3 Plain-Symfony responders (interop)

Documented convention + worked example (AMQP shown, pattern portable):

1. Handler receives the message; reads `X-Nexus-Correlation-Id` and `X-Nexus-Reply-To` (via the transport's received stamp / our serializer's stamps when using it).
2. Sends the reply message to its configured transport matching the reply-to name, copying `X-Nexus-Correlation-Id`.
3. No Nexus dependency required; optional convenience: the guide shows a 10-line `NexusReplyer` helper users can paste.

## 6. Wiring / DX

`MessengerBridge` gains:

- `askSupport(ActorSystem $system, ReplyChannelFactory $factory, ?int $maxPending = null, ...): AskSupport` — builds registry + lazily-spawned reply consumer; returns a handle passed to `producer()` (new trailing optional param) so refs become ask-capable.
- `receiverProps()`/`spawnReceivers()` gain trailing optional `?ReplySenderLocator $replySenders` — enables responder-side ask handling; without it, ask-stamped messages are handled per unroutable policy for replies (processed as normal tells, reply impossible → warn once).

## 7. Semantics summary

- At-least-once requests: duplicates possible → responder may reply twice → asker first-reply-wins, late drops counted.
- `AskTimeoutException` identical to local ask; timeout does not cancel the remote work (documented).
- Registry cap → `AskCapacityExceededException` fail-fast.
- Backpressure: ask publishes are producer-side sends (no mailbox involved); responder-side delivery uses the existing offer/ack machinery, with process-ack per §5.2.

## 8. Observability

- Spans: `messenger.ask` (Producer, child of current; ends on resolve/timeout with outcome attr `resolved|timeout|capacity_rejected`), reply consumption reuses `messenger.receive` with `nexus.messenger.outcome = reply_resolved|reply_dropped`. Trace context propagates on both legs via the existing `TraceContextStamp`.
- Metrics: `nexus.messenger.asks.sent|resolved|timed_out|capacity_rejected`, `nexus.messenger.replies.dropped`, `nexus.messenger.asks.pending` (observable gauge), `nexus.messenger.asks.unroutable_reply_to`.
- PSR-14 events: `AskStarted(message, correlationId)`, `AskResolved(correlationId, reply)`, `AskTimedOut(correlationId)`, `ReplyPublished(correlationId)`.

## 9. Testing strategy

- Unit: registry (cap, first-wins, late-drop, timeout removal), stamps/serializer headers round-trip, `MessengerReplyRef` publish+ack callback, reply-to locator rejection.
- Integration (Fiber, InMemory transports as both request + reply channels): full ask→consume→reply→resolve loop; timeout path; duplicate-reply drop; process-ack (request not acked until reply sent — assert via transport ack ordering); unconfigured-ask throws; capacity fail-fast.
- Interop test: responder side implemented as a bare closure "plain Symfony handler" reading headers off the encoded envelope and replying manually — proving the convention without Nexus on that side.
- Console: `nexus:messenger:consume` gains `--reply-senders` wiring (follow-up task in plan; thread variant documents ask-responders work per thread unchanged).

## 10. Explicitly out of scope

- Streaming/multi-reply per ask (one reply resolves; rest drop).
- Distributed pending-ask registry (per-process only; Persistent lifecycle documented as single-consumer).
- Cancellation propagation to the responder on timeout.
- Broker-native RPC fast paths (RabbitMQ direct-reply-to) — possible later behind `ReplyChannelFactory` without protocol changes.

## 11. Documentation deliverables

Package page ask section + option/lifecycle tables; guide chapter "Request/reply over the broker" (Nexus↔Nexus, plain-Symfony responder walkthrough with AMQP example, lifecycle strategy choice, SQS caveat: prefer Persistent or generic fallback due to queue-creation latency); reference pages for `PendingAskRegistry`-visible config and new exceptions; CLAUDE.md; example app extension (`bin/ask.php`).
