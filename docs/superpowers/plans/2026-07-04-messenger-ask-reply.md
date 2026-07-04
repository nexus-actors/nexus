# Messenger Broker Request/Reply (`ask()`) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement broker request/reply per `docs/superpowers/specs/2026-07-04-messenger-ask-reply-design.md` — `MessengerActorRef::ask()` working over any Symfony Messenger transport, with plain-Symfony responder interop — on branch `feat/nexus-messenger` (PR #50).

**Architecture:** Asker parks a `FutureSlot` in a bounded `PendingAskRegistry` keyed by correlation id, publishes with `CorrelationIdStamp` + `ReplyToStamp` (logical name), and a lazily-spawned reply consumer resolves slots from a reply channel that is itself a Messenger transport (DSN-template factory, switchable lifecycle). Responder-side `ReceiverActor` detects ask stamps, resolves the reply-to name via a `ReplySenderLocator`, delivers with `senderRef = MessengerReplyRef` so `$ctx->reply()` works unchanged, and defers the broker ack until the reply is published (process-ack).

**Tech Stack:** Existing nexus-messenger bridge (PR #50 tip), symfony/messenger 8.x, `Monadial\Nexus\Runtime\Async\FutureSlot`, PHPUnit 13, Psalm level 1.

## Global Constraints

- Branch `feat/nexus-messenger`, same PR #50. All commands through Docker. NEVER add Claude attribution to commits. GrumPHP (cs-fixer, phpcs, psalm, full `unit` suite) gates every commit.
- `nexus-messenger` composer requires DO NOT grow (symfony/messenger + existing nexus deps only). All new params trailing + optional; every pre-existing test passes unmodified.
- Style: strict_types, final classes, readonly value objects, `#[Override]`, blank line before control structures, alphabetically sorted string-keyed array literals, multi-line ternaries, ordered imports, trailing commas, `@psalm-api` docblocks with usage examples on public classes.
- Wire headers exactly: `X-Nexus-Correlation-Id`, `X-Nexus-Reply-To`. Reply-to on the wire is a LOGICAL NAME; responders resolve via configured map only — never construct a transport from a wire value (SSRF hardening).
- Telemetry fail-safe everywhere (swallow pattern); observability names exactly as spec §8.
- Grounded API facts (verified — do not re-derive): `FutureSlot { resolve(object): void; fail(FutureException): void; isResolved(): bool }`; `Runtime::createFutureSlot(): FutureSlot`; `Runtime::scheduleOnce(Duration, callable): Cancellable`; core `FutureRef(ActorPath, FutureSlot)` is the local analog of our reply resolution; `LocalActorRef::enqueueEnvelope(Envelope): void`; core `Envelope::of(message, sender, target)` + `withSenderRef(ActorRef)` + `withCorrelationId(string)`; Symfony `TransportFactoryInterface::createTransport(string $dsn, array $options, SerializerInterface): TransportInterface`, `SetupableTransportInterface::setup(): void`, `InMemoryTransportFactory` exists for tests; `AskTimeoutException(ActorPath $path, Duration $timeout)` is the existing timeout exception.
- Implementers: the codebase is the ground truth for existing bridge classes (`MessengerActorRef`, `ReceiverActor`, `NexusMessengerSerializer`, `MessengerBridge`) — read them before modifying; this plan gives exact new signatures and behavioral contracts, not verbatim re-dumps of files you can open.

---

### Task A1: Ask stamps + serializer headers

**Files:**
- Create: `packages/nexus-messenger/src/Stamp/CorrelationIdStamp.php`, `packages/nexus-messenger/src/Stamp/ReplyToStamp.php`
- Modify: `packages/nexus-messenger/src/Serialization/NexusMessengerSerializer.php`
- Test: extend `packages/nexus-messenger/tests/Unit/Serialization/NexusMessengerSerializerTest.php` + `packages/nexus-messenger/tests/Unit/Stamp/StampsTest.php`

**Interfaces — Produces:**
- `final readonly class CorrelationIdStamp implements StampInterface { public function __construct(public string $id) {} }`
- `final readonly class ReplyToStamp implements StampInterface { public function __construct(public string $channel) {} }`
- Serializer round-trips them as `X-Nexus-Correlation-Id` / `X-Nexus-Reply-To` string headers (empty-string header values skipped on decode, mirroring the existing source/target path stamps exactly).

**Steps (TDD):**
- [ ] Failing tests: stamps carry values + are `StampInterface`; serializer encode adds both headers when stamps present; decode restores both stamps; empty/missing headers → no stamps; existing round-trip tests untouched.
- [ ] Run to RED (`docker compose exec php vendor/bin/phpunit packages/nexus-messenger/tests/Unit`), implement (mirror `SourceActorPathStamp` handling in `headersFor()`/`stampsFromHeaders()`, keep header constants alphabetically ordered), run to GREEN.
- [ ] `make cs-fix && make phpcs && make psalm`; commit: `feat(messenger): correlation and reply-to stamps with wire headers`

### Task A2: `PendingAskRegistry` + exceptions

**Files:**
- Create: `packages/nexus-messenger/src/Ask/PendingAskRegistry.php`, `packages/nexus-messenger/src/Exception/AskCapacityExceededException.php`
- Test: `packages/nexus-messenger/tests/Unit/Ask/PendingAskRegistryTest.php`

**Interfaces — Produces:**
```php
final class PendingAskRegistry
{
    public function __construct(private readonly int $maxPending = 10_000) {}
    /** @throws AskCapacityExceededException when at capacity */
    public function register(string $correlationId, FutureSlot $slot): void;
    /** First reply wins; returns false for unknown/late/duplicate (caller logs+counts). */
    public function resolve(string $correlationId, object $reply): bool;
    /** Timeout path: remove without resolving; returns the slot or null. */
    public function remove(string $correlationId): ?FutureSlot;
    public function count(): int;
}
```
- `final class AskCapacityExceededException extends NexusException` (message includes cap and current count).

**Steps (TDD):** failing tests for register/resolve happy path (slot resolved with the reply object), duplicate resolve returns false and does not re-resolve, unknown id false, remove clears (subsequent resolve false), cap boundary (`maxPending` inclusive: registering at cap throws; message names cap), count. RED → implement (plain array map; no locking needed — single-threaded per process) → GREEN → checks → commit: `feat(messenger): bounded pending-ask registry with first-reply-wins`

### Task A3: Reply channel abstraction + transport-backed factory + sender locator

**Files:**
- Create: `packages/nexus-messenger/src/Ask/ReplyChannel.php`, `Ask/ReplyChannelFactory.php`, `Ask/ReplyQueueLifecycle.php`, `Ask/TransportReplyChannelFactory.php`, `Ask/ReplySenderLocator.php`, `Ask/MapReplySenderLocator.php`
- Test: `packages/nexus-messenger/tests/Unit/Ask/TransportReplyChannelFactoryTest.php`, `MapReplySenderLocatorTest.php`

**Interfaces — Produces:**
```php
interface ReplyChannel { public function name(): string; public function receiver(): ReceiverInterface; public function close(): void; }
interface ReplyChannelFactory { public function create(): ReplyChannel; }
enum ReplyQueueLifecycle { case DeleteOnShutdown; case Ephemeral; case Persistent; }
final readonly class TransportReplyChannelFactory implements ReplyChannelFactory
{
    /** @param array<string, mixed> $options passed to createTransport */
    public function __construct(
        private TransportFactoryInterface $transportFactory,
        private SerializerInterface $serializer,
        private string $dsnTemplate,            // placeholders: {instance}, {name}
        private string $channelName,            // logical name advertised in X-Nexus-Reply-To
        private ReplyQueueLifecycle $lifecycle = ReplyQueueLifecycle::Ephemeral,
        private array $options = [],
    ) {}
    public function create(): ReplyChannel;
}
interface ReplySenderLocator { /** null = unknown name (reject) */ public function senderFor(string $channel): ?SenderInterface; }
final readonly class MapReplySenderLocator implements ReplySenderLocator
{ /** @param array<string, SenderInterface> $senders */ public function __construct(private array $senders) {} }
```
**Behavioral contract:** `create()` substitutes `{instance}` with a fresh ULID (`Symfony\Component\Uid\Ulid` is NOT a messenger dep — use `bin2hex(random_bytes(8))` instead) and `{name}` with the channel name; calls `createTransport($dsn, $options, $serializer)`; for `Ephemeral`/`DeleteOnShutdown` calls `setup()` when the transport is `SetupableTransportInterface`; for `Persistent` requires the template to contain no `{instance}` (throw `InvalidArgumentException` otherwise) and skips setup. The returned channel's `close()`: no-op for `Ephemeral`/`Persistent`; for `DeleteOnShutdown`, best-effort teardown ONLY if the transport exposes a usable teardown (Symfony has no universal delete API — document that TTL remains the crash backstop and `close()` may be a logged no-op for transports without one). `name()` returns `$channelName`. The Persistent single-consumer warning goes in the class docblock.

**Steps (TDD):** unit tests use `InMemoryTransportFactory` (`supports('in-memory://...')`): create() returns channel whose receiver is the built transport; `{instance}` produces distinct DSNs across two create() calls (capture via a recording decorator around the factory); Persistent + `{instance}` template throws; locator returns sender/null. RED → implement → GREEN → checks → commit: `feat(messenger): reply channels as Messenger transports with lifecycle strategies`

### Task A4: Responder side — `MessengerReplyRef` + ask-aware `ReceiverActor` with process-ack

**Files:**
- Create: `packages/nexus-messenger/src/Ask/MessengerReplyRef.php`
- Modify: `packages/nexus-messenger/src/Consumer/ReceiverActor.php` (+ `MessengerBridge::receiverProps/spawnReceivers` gain trailing `?ReplySenderLocator $replySenders = null`)
- Test: `tests/Integration/Messenger/AskResponderTest.php` (+ unit test for MessengerReplyRef with RecordingSender)

**Interfaces — Produces:**
```php
/** @template-implements ActorRef<object> */
final readonly class MessengerReplyRef implements ActorRef
{
    /** @param Closure(): void $ackCallback fired once after a successful reply publish */
    public function __construct(
        private SenderInterface $sender,
        private string $correlationId,
        private Closure $ackCallback,
        Observability $observability = new NoopObservability(),
        ?EventDispatcherInterface $events = null,
    ) {}
    // tell(): publish reply Envelope with CorrelationIdStamp($correlationId) (+ trace stamp when enabled),
    //         then fire $ackCallback exactly once (idempotent guard via \WeakMap? No — readonly class: use a
    //         one-shot \Closure wrapper created by ReceiverActor instead; the ref calls it unconditionally,
    //         the wrapper self-disarms). Dispatch ReplyPublished event; nexus.messenger.replies.sent counter.
    // ask(): throws UnsupportedOperationException. path(): /messenger/reply/<correlationId>. isAlive(): true.
}
```
**ReceiverActor behavioral contract (the core of this task — read the current `drainOnce()` carefully first):**
- New trailing param on `create()`: `?ReplySenderLocator $replySenders = null` (threaded through `MessengerBridge`).
- Per drained envelope, when BOTH `CorrelationIdStamp` and `ReplyToStamp` are present AND the locator is configured:
  1. `$sender = $locator->senderFor($stamp->channel)`; null → `$receiver->reject($envelope)`, warning log `Unknown reply-to channel`, counter `nexus.messenger.asks.unroutable_reply_to`, outcome attr `reply_to_rejected`, continue. (Never build transports from wire values.)
  2. Target must be a `LocalActorRef` (needs `enqueueEnvelope`): build core `Envelope::of($inner, ActorPath::root(), $target->path())->withCorrelationId($correlationId)->withSenderRef($replyRef)` and deliver via `enqueueEnvelope()`. For non-local targets, fall back to plain `tell()` + immediate ack + one warning log (reply impossible — documented limitation).
  3. **Process-ack:** do NOT ack now. Create the one-shot ack closure `static function () use ($receiver, $envelope, ...): void { $receiver->ack($envelope); }` wrapped in the swallow pattern, hand it to the `MessengerReplyRef`. Track pending asks in a bounded in-actor map correlation → [envelope, deadline]; on each Poll tick, expire entries older than a `Duration $askPendingTimeout` (new `ReceiverActorConfig` field, default 30s, wither `withAskPendingTimeout()`) → `$receiver->reject($envelope)` for redelivery + counter `nexus.messenger.asks.responder_expired`. Processed count/`MessagesProcessed` increments on DELIVERY (enqueue accepted), as today.
  4. Backpressure/dropped handling for the enqueue is unchanged (no ack, early return) — `enqueueEnvelope` returns void, so use `$target->offer()`-equivalent ordering: check `BackpressureCapable` FIRST with the inner message? NO — the envelope must carry the senderRef, and `offer()` takes a bare message. Resolution: extend the core seam minimally — add `offerEnvelope(Envelope $envelope): EnqueueResult` to `LocalActorRef` ONLY (not the interface; `instanceof LocalActorRef` check in this path), mirroring `enqueueEnvelope` but returning the result. One new core test mirroring `LocalActorRefOfferTest`.
- Messages WITHOUT ask stamps: byte-identical behavior to today (assert by leaving all existing tests untouched).
- When ask stamps present but locator NOT configured: treat as plain tell (deliver without senderRef, normal ack) + one-per-actor-lifetime warning log `Ask received but no ReplySenderLocator configured`.

**Integration tests (Fiber + InMemoryTransport as request transport, second InMemoryTransport as reply sender):** (1) full responder path — seed request envelope with both stamps; actor handler calls `$ctx->reply(new Pong(...))`; assert reply transport got exactly one envelope with correct `CorrelationIdStamp` + body, request acked AFTER reply (ack ordering: assert not-acked while handler stalls via a two-message sequence, then acked at end), processed counter incremented. (2) unknown reply-to → rejected + not delivered. (3) no locator → delivered as plain tell, acked, warning logged. (4) responder expiry — handler never replies; after `withAskPendingTimeout(Duration::millis(100))`, request rejected for redelivery.
**Steps:** core `offerEnvelope` (TDD in nexus-core) → MessengerReplyRef unit (RecordingSender: reply published with stamp, ack callback fired once, ask() throws) → ReceiverActor changes → integration tests → full unit suite + `--testsuite=integration-messenger` → checks → commit: `feat(messenger): ask-aware receiver with process-ack and MessengerReplyRef`

### Task A5: Asker side — `AskSupport`, reply consumer, `MessengerActorRef::ask()`

**Files:**
- Create: `packages/nexus-messenger/src/Ask/AskSupport.php`, `packages/nexus-messenger/src/Ask/ReplyConsumer.php` (behavior factory, internal)
- Modify: `packages/nexus-messenger/src/Producer/MessengerActorRef.php`, `packages/nexus-messenger/src/MessengerBridge.php`
- Test: `packages/nexus-messenger/tests/Unit/Producer/MessengerActorRefTest.php` (extend), `tests/Integration/Messenger/AskReplyLoopTest.php`

**Interfaces — Produces:**
```php
final class AskSupport
{
    public function __construct(
        private readonly ActorSystem $system,
        private readonly ReplyChannelFactory $channelFactory,
        private readonly PendingAskRegistry $registry,
        private readonly Duration $pollInterval, // default millis(20)
    ) {}
    public function registry(): PendingAskRegistry;
    /** Lazily creates the channel + spawns the reply consumer on first call; idempotent. */
    public function replyChannelName(): string;
    public function close(): void; // channel close-through, called from docs'd shutdown hook
}
// MessengerBridge additions:
public static function askSupport(ActorSystem $system, ReplyChannelFactory $factory, ?int $maxPending = null, ?Duration $replyPollInterval = null): AskSupport;
// producer() gains trailing: ?AskSupport $askSupport = null  → passed to MessengerActorRef ctor (trailing optional).
```
**`MessengerActorRef::ask()` contract:** unconfigured (`$askSupport === null`) → keep throwing `UnsupportedOperationException` but with the new message pointing at `MessengerBridge::askSupport()`. Configured: correlation id = `bin2hex(random_bytes(16))`; `$slot = runtime->createFutureSlot()` (runtime NOT currently on the ref — add trailing optional `?Runtime $runtime = null` to `askSupport()`-built refs? NO: `AskSupport` holds the system → `$system->runtime()`; `ask()` delegates to `AskSupport::ask($message, $timeout, $stamps)` which owns slot/registry/timeout/publish mechanics and returns `Future`; the ref adds its own source/trace stamps and sender). Registry cap exceeded → `AskCapacityExceededException` propagates. Timeout via `runtime->scheduleOnce`: `registry->remove($id)` then `slot->fail(new AskTimeoutException($this->path(), $timeout))` (guard: only if remove returned non-null). Publish uses the ref's existing send span machinery with `messaging.operation = ask` and span name `messenger.ask`, outcome attr `resolved|timeout|capacity_rejected` set by AskSupport when known (span ends at publish; resolution outcome tracked via metrics, not the span — keep the span lifecycle simple and document it).
**`ReplyConsumer`:** a behavior spawned as `'nexus-ask-replies'` reusing `ReceiverActor::create()` with a fixed internal `MessageRouter`? NO — replies must resolve the registry, not route to actors: implement as a dedicated slim behavior (mirror ReceiverActor's Poll loop shape) that polls `ReplyChannel::receiver()`, for each envelope reads `CorrelationIdStamp` (missing → ack + drop + debug), calls `registry->resolve($id, $inner)`, acks ALWAYS (late replies are consumed, `false` → `nexus.messenger.replies.dropped` counter + debug log), busy/idle poll cadence identical to ReceiverActor.
**Metrics/events (fail-safe):** `nexus.messenger.asks.sent|resolved|timed_out|capacity_rejected` counters, `nexus.messenger.asks.pending` observable gauge registered by AskSupport (`fn() => $registry->count()`), events `AskStarted(object $message, string $correlationId)`, `AskResolved(string $correlationId, object $reply)`, `AskTimedOut(string $correlationId)`, `ReplyPublished(string $correlationId)` (A4) in `Monadial\Nexus\Messenger\Event`.
**Integration tests (Fiber; InMemory request transport + `TransportReplyChannelFactory` over `InMemoryTransportFactory`):** (1) full loop — asker system + responder receiver in ONE ActorSystem: `$future = $ref->ask(new Ping('x'), Duration::seconds(2))` from inside an actor (`spawnTask`/probe actor calling await — mirror how `AskPatternTest` awaits on Fiber), responder actor replies; assert resolved value, request acked after reply, counters. (2) timeout — no responder; assert `AskTimeoutException`, registry emptied, `asks.timed_out` counted. (3) duplicate reply — seed two replies with same correlation id onto the reply channel; assert first resolves, second dropped+acked. (4) capacity — registry cap 1, second concurrent ask throws `AskCapacityExceededException`. (5) unconfigured ref still throws `UnsupportedOperationException`.
**Steps:** unit RED/GREEN for ref throw-message + AskSupport basics → implement → integration → suites → checks → commit: `feat(messenger): broker ask with reply consumer, timeouts, and capacity fail-fast`

### Task A6: Interop proof + console wiring

**Files:**
- Test: `tests/Integration/Messenger/PlainSymfonyResponderTest.php`
- Modify: `packages/nexus-messenger-console/src/ConsumeCommand.php` (+ test), `examples/nexus-messenger-redis/bin/console` + example README ask mention
**Contract:** (1) Interop test — responder side is a bare closure, NOT Nexus: it pulls the encoded envelope from the request transport **via `NexusMessengerSerializer::encode()`-produced headers** (simulate a foreign consumer: `$serializer->encode($envelope)` → read `X-Nexus-Correlation-Id`/`X-Nexus-Reply-To` from the headers array → build a reply as a plain associative `['body' => ..., 'headers' => ['type' => ..., 'X-Nexus-Correlation-Id' => $id]]` → `$serializer->decode()` it onto the reply transport via `$replySender->send()`). Asserts the documented header convention is sufficient without any Nexus classes on the responder path. (2) `ConsumeCommand` gains trailing ctor param `?ReplySenderLocator $replySenders = null` threaded to `spawnReceivers`; one CommandTester test proving an ask-stamped seeded message gets replied when the command runs with a locator + a `CallbackConsumerSetup` responder. (3) Example `bin/console` wires a `MapReplySenderLocator` with a `replies` Redis transport (code only; runtime-untested as before).
**Steps:** tests first where practical → implement → suites → checks → commit: `feat(messenger): plain-Symfony responder interop and console reply wiring`

### Task A7: Docs

**Files:** `website/docs/packages/messenger.md` (ask section: wiring, lifecycle table, semantics, metrics/events additions), `website/docs/guides/messenger-bridge.md` (new chapter "Request/reply over the broker": Nexus↔Nexus walkthrough, plain-Symfony AMQP responder example with the two headers, lifecycle strategy choice incl. SQS caveat + Persistent single-consumer warning, timeout/duplicate semantics, capacity), `website/docs/reference/classes/messenger-actor-ref.md` (ask() no longer "throws" — document configured/unconfigured), `reference/exceptions.md` (`AskCapacityExceededException`), `reference/config.md` (askPendingTimeout, maxPending, lifecycle enum), CLAUDE.md messenger section ask bullet, CHANGELOG bullet.
**Gate:** every snippet verified against the shipped signatures; `cd website && npm run build` passes. Commit: `docs(messenger): request/reply guide, ask reference, and configuration docs`

### Task A8: Final verification + push to PR #50

- Full battery: unit (php), integration-messenger, unit-swoole + integration-swoole + integration-worker-pool-swoole (php-swoole, with `timeout 300` wrappers), psalm, deptrac, cs/phpcs, composer validate, website + landing builds.
- Push `feat/nexus-messenger`; update the PR #50 body with an "Ask / request-reply" section (features, process-ack semantics, interop convention, security note on logical reply-to names).

## Execution notes

- Order strict A1→A5; A6 needs A5; A7 after code final; A8 last. The user's parallel commits may interleave — always record exact base SHAs per task, stage only task files.
- Deviations from spec discovered against the codebase: adjust locally, note in the task report — do not redesign. The two most likely friction points are flagged inline: `offerEnvelope` core seam (A4.4) and awaiting a Future inside Fiber tests (mirror `AskPatternTest`).
