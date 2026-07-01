# Nexus ↔ Symfony Messenger Bridge — Design

- **Date:** 2026-07-01
- **Status:** Approved design, ready for implementation plan
- **Package:** `nexus-messenger` (new)
- **Scope of v1:** Two-way bridge (producer + consumer) between Nexus actors and the
  standalone `symfony/messenger` component. Cluster transport is explicitly deferred.

## 1. Motivation

Nexus actors need a first-class way to exchange messages with the outside world over
message brokers (AMQP, Redis, Doctrine, …) without hand-rolling transport code. Symfony
Messenger already provides a mature, standalone transport abstraction (senders, receivers,
stamps, retry/failure transports) that we can bridge into the actor model.

This bridge serves three scenarios with one neutral design:

1. **Nexus as a queue worker** — a standalone Nexus service consumes from a broker, processes
   in actors, and publishes results back.
2. **Embed in a Symfony app** — an existing Symfony app dispatches domain messages; Nexus
   actors handle some of them, and actors emit events back onto Messenger buses.
3. **Durable async boundary** — a Messenger transport as a broker-backed, restart-surviving
   boundary between two actors or actor systems.

**Non-goal / clarification:** this is *not* a Nexus `Runtime`. A `Runtime` is a concurrency
backend (mailboxes, fibers/coroutines, timers, event loop). Messenger provides none of those.
The "messenger runtime" instinct resolves into two concrete pieces that live in this bridge:
a **producer `ActorRef`** and a **supervised consumer actor**.

## 2. Constraints

- Depend only on the **standalone `symfony/messenger`** component. Never depend on
  `symfony/framework-bundle` or the full framework.
- **No `symfony/console` dependency.** Worker recycling and process control are handled by a
  supervised actor (see §7), not a CLI command.
- `nexus-core` stays dependency-free. The bridge depends on `nexus-core`,
  `nexus-serialization`, `nexus-runtime`, and `symfony/messenger`.
- Follow existing Nexus conventions: `final` classes, `readonly` value objects, PER-CS2.0 +
  Slevomat, Psalm level 1, alphabetically sorted string-keyed arrays, blank line before control
  structures, multi-line ternaries.

## 3. Package layout

```
packages/nexus-messenger/
├── composer.json                 # requires: nexus-core, nexus-serialization,
│                                 #           nexus-runtime, symfony/messenger
├── src/
│   ├── Producer/
│   │   ├── MessengerActorRef.php     # ActorRef<T> backed by a Messenger SenderInterface
│   │   └── MessengerGateway.php      # explicit ->publish() egress service
│   ├── Consumer/
│   │   ├── ReceiverActor.php         # supervised poll→route→ack loop (one per receiver)
│   │   └── ReceiverActorConfig.php   # poll interval, unroutable policy, etc.
│   ├── Routing/
│   │   ├── MessageRouter.php         # interface
│   │   ├── MapMessageRouter.php      # default: message class -> ActorRef
│   │   └── StampMessageRouter.php    # cluster seam: target-path stamp -> path registry
│   ├── Serialization/
│   │   └── NexusMessengerSerializer.php  # Messenger SerializerInterface via nexus-serialization
│   ├── Lifecycle/
│   │   └── LifecycleWatchdog.php      # worker-recycling actor (memory/time/count -> shutdown)
│   ├── Stamp/
│   │   ├── TargetActorPathStamp.php   # reserved for StampMessageRouter / cluster
│   │   └── SourceActorPathStamp.php   # provenance of an egress message
│   └── NexusMessengerBundleWiring.php # NexusApp/bootstrap conveniences (plain helpers)
└── tests/
    └── Unit/...
```

Package name and exact namespace (`Monadial\Nexus\Messenger\…`) to be confirmed at
implementation time; matches the existing `Monadial\Nexus\*` convention.

## 4. Producer — actor → Messenger

Two APIs over one underlying `SenderInterface`.

### 4.1 `MessengerActorRef<T> implements ActorRef<T>`

The idiomatic, location-transparent API. Actor code is byte-identical to a local send.

- `tell(object $message): void` — wraps `$message` in a Symfony `Envelope`, optionally stamps
  `SourceActorPathStamp`, and calls `$sender->send($envelope)`.
- `ask(callable, Duration): mixed` — **throws `UnsupportedOperation` in v1.** Broker
  request/reply requires correlation stamps and a reply transport; deferred to v-next.
- `path(): ActorPath` — a synthetic path identifying the transport target (e.g.
  `messenger://<sender-name>`).
- `isAlive(): bool` — returns `true` (the transport is assumed available; delivery failures
  surface as exceptions from `send()`).

Message eligibility: the existing `NonSerializableRemoteMessageRule` Psalm plugin already
governs cross-boundary sends. `MessengerActorRef::tell()` messages should carry a
`#[MessageType]` attribute the same way `WorkerActorRef` messages do (to be confirmed against
the plugin's current target list during implementation).

### 4.2 `MessengerGateway`

An explicit egress service for code that wants to be deliberate that "this leaves the system":

- `publish(object $message, ?Envelope $envelope = null): void`

Both `MessengerActorRef` and `MessengerGateway` delegate to the same `SenderInterface`.

## 5. Consumer — Messenger → actor (`ReceiverActor`)

One supervised `ReceiverActor` per `ReceiverInterface`, spawned via a `Props` factory.

### 5.1 Loop

Messenger's `ReceiverInterface::get()` is non-blocking by design: it returns the 0..N
envelopes available *now* and returns immediately when the queue is empty (this is how
Symfony's own `Worker` drives it). That maps cleanly onto an actor tick:

1. Call `$receiver->get()`.
2. For each Symfony `Envelope`: resolve target via `MessageRouter`, then `tell()` the wrapped
   message into the target actor's mailbox.
3. On enqueue result:
   - `Accepted` → `$receiver->ack($envelope)`. Increment processed counter (reported to the
     watchdog, §7).
   - `Backpressured` / `Dropped` → **do not ack**, stop pulling for this tick. Backpressure
     propagates to the broker; the message is redelivered later.
   - Unroutable (no route found) → **`reject()` by default** (configurable to forward to
     `ActorSystem::deadLetters()` via `ReceiverActorConfig`).
4. If `get()` returned nothing → cooperative `runtime->sleep(pollInterval)` (yields to other
   actors; no busy-wait).

### 5.2 Delivery semantics

- **At-least-once**, ack-on-accepted-enqueue. Small risk window (crash after ack, before the
  target actor processes) is accepted as the default trade-off for throughput.
- Stronger process-ack (await processing via `ask()`) is **not** in v1.

### 5.3 Reliability

- **Supervision** — a receiver crash (broker blip) restarts the actor with backoff via the
  standard `SupervisionStrategy`. No custom retry loop.
- **Backpressure** — full target mailbox naturally throttles broker consumption (§5.1).
- **Graceful shutdown** — `onSignal(PostStop)` stops the poll loop so in-flight acks settle
  before the system exits. Integrates with `ActorSystem::shutdown()`'s deadline drain.

### 5.4 Fiber vs Swoole

- **Swoole** — with coroutine-aware transport clients, `get()` is fully non-blocking. Ideal.
- **Fiber** — each `get()` performs a short synchronous network read (a few ms). Acceptable for
  most workloads but not truly async. Fully-async Fiber clients are out of scope for v1.

## 6. Routing — pluggable, map default

`MessageRouter` interface resolves an incoming message + Symfony `Envelope` to a target
`ActorRef` (or "unroutable").

- **`MapMessageRouter`** (shipped default) — message class → registered `ActorRef`. Simple,
  declarative, covers the common case.
- **`StampMessageRouter`** (reserved cluster seam) — reads `TargetActorPathStamp` and resolves
  via a path → `ActorRef` registry. Built now as the interface seam; fully wired when cluster
  transport lands. This is the reuse point that makes "Messenger as cluster backbone" a later
  increment rather than a rewrite.

## 7. Lifecycle / worker-recycling — `LifecycleWatchdog`

Long-running PHP processes leak over time; the standard defense is "gracefully stop after N
messages / X memory / T uptime, let the process manager restart." Actors do not provide this
for free, and we deliberately do **not** ship a `messenger:consume`-style CLI command.

`LifecycleWatchdog` is a supervised actor that self-ticks and triggers
`ActorSystem::shutdown(Duration)` when any configured threshold is reached:

- **Memory limit** — `memory_get_usage(true)` exceeds a configured byte budget.
- **Time limit** — uptime exceeds a configured `Duration`.
- **Message limit** — cumulative processed count (reported by `ReceiverActor`(s)) exceeds a
  configured count.

Properties:

- Works in **any** host model — a Nexus-owned process *or* inside a `messenger:consume` worker.
- No `symfony/console` dependency; pure actor + `ActorSystem::shutdown()`.
- Relies on an external process manager (systemd / supervisor / k8s) to restart after graceful
  exit — matching the operational model users already expect from Messenger workers.

## 8. Serialization — pluggable, Nexus-backed default

`NexusMessengerSerializer implements
Symfony\Component\Messenger\Transport\Serialization\SerializerInterface`, backed by
`nexus-serialization`'s `MessageSerializer`:

- `encode(Envelope): array` / `decode(array): Envelope` — delegate body (de)serialization to
  the Nexus serializer, producing a clean, interop-friendly JSON envelope; preserve stamps.
- **Swappable** — any Messenger `SerializerInterface` (including Symfony's native serializer)
  can be substituted for raw interop with non-Nexus producers/consumers.

Default = Nexus-backed for consistency and future Nexus-to-Nexus (cluster) reuse.

## 9. Host models (deployment)

Both are supported by the same code; the difference is who owns the OS process:

- **Nexus owns the process** (queue-worker scenario) — user boots `NexusApp` with receiver
  actor(s) + the watchdog and calls `run()`. No `messenger:consume`. Recommended for the
  standalone-service case.
- **Messenger owns the process** (embed-in-Symfony) — the real `messenger:consume` runs; the
  bridge contributes only the routing handler + serializer. The `LifecycleWatchdog` still works
  here if a Nexus system is booted in-process.

## 10. Wiring / DX

Plain bootstrap helpers (no framework, no container magic) plus `NexusApp` conveniences to, in
a few lines:

- register a `SenderInterface` and expose a `MessengerActorRef` / `MessengerGateway`;
- register a `ReceiverInterface` + `MessageRouter` and spawn a `ReceiverActor`;
- register the `LifecycleWatchdog` with thresholds.

Standalone Messenger requires manually building the bus/transports; the helpers keep that to a
minimal, explicit surface.

## 11. Explicitly out of scope for v1

- **Cluster transport over Messenger** — `StampMessageRouter` + `TargetActorPathStamp` are the
  seams left in place; full cluster wiring is a later increment.
- **Broker request/reply on `ask()`** — needs correlation stamps + reply transport.
- **Swoole coroutine-native receiver clients** — v1 relies on short synchronous polls, which
  work on both runtimes.
- **`messenger:consume` reimplementation** — replaced by `ReceiverActor` (loop) +
  `LifecycleWatchdog` (recycling).

## 12. Testing strategy

- **Unit** (`packages/nexus-messenger/tests/Unit/`):
  - `MapMessageRouter` / `StampMessageRouter` resolution, including unroutable.
  - `NexusMessengerSerializer` round-trip (encode → decode), stamp preservation.
  - `MessengerActorRef::tell()` delegates to a fake `SenderInterface`; `ask()` throws
    `UnsupportedOperation`.
  - `LifecycleWatchdog` triggers `shutdown()` at each threshold (memory/time/count) using
    `TestClock` and injected memory probe.
- **Integration** (Fiber, using in-memory Messenger transports):
  - `ReceiverActor` end-to-end: produced message → consumed → routed → actor receives; verify
    ack on `Accepted`, no-ack + redelivery on `Backpressured`/`Dropped`, reject/dead-letter on
    unroutable.
  - Producer → in-memory transport → `ReceiverActor` → target actor full loop.
- Follow the existing Fiber integration pattern (`ActorSystem::create` + `scheduleOnce`
  shutdown + assert captured state). Inject a fake/synchronous transport to keep tests
  deterministic.

## 13. Open questions resolved

- **`ask()` on `MessengerActorRef`** → deferred; throws `UnsupportedOperation` in v1.
- **Unroutable inbound message** → `reject()` by default; configurable to `deadLetters()`.
- **Worker recycling** → `LifecycleWatchdog` actor, not a CLI command.
- **Serialization** → pluggable, Nexus-backed default.
- **Inbound routing** → pluggable `MessageRouter`, `MapMessageRouter` default, stamp strategy as
  the cluster seam.
