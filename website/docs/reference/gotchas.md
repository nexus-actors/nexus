---
title: Gotchas
related:
  - reference/exceptions
  - reference/lifecycle-signals
  - reference/system-messages
  - core-concepts/supervision
---

# Gotchas

This page catalogs 21 constraints and edge cases that experienced Nexus developers encounter. Each entry states the constraint, links to the relevant deep-dive page, and calls out the consequence.

---

### Messages must be readonly classes

Every object passed to `tell()`, `scheduleOnce()`, or `scheduleRepeatedly()` must be declared `readonly class`. Nexus actors may run on separate fibers or threads; a mutable message shared across actor boundaries causes data races.

**Enforce it:** The `ReadonlyMessageRule` Psalm hook reports `NonReadonlyMessage` at the call site. See [Psalm rules](./psalm-rules.md#readonlymessagerule).

:::caution Consequence
Passing a mutable object produces a Psalm error and may cause silent corruption in multi-threaded worker-pool deployments.
:::

---

### Actor names follow a strict pattern

Actor names passed to `$ctx->spawn(Props, $name)` may contain only letters (`a-zA-Z`), digits (`0-9`), hyphens (`-`), and underscores (`_`). Slashes, dots, and Unicode are rejected with `InvalidActorPathException`.

**Why:** Actor paths are URL-like hierarchical identifiers; special characters break path parsing.

**See also:** [Exceptions — InvalidActorPathException](./exceptions.md#invalidactorpathexception)

:::caution Consequence
A dynamic name derived from user input (e.g., an email address) containing `@` or `.` throws `InvalidActorPathException` at spawn time.
:::

---

### Child names must be unique per parent

`$ctx->spawn(Props, $name)` throws `ActorNameExistsException` if a child with that name is already alive under the parent. Names are unique per parent, not globally.

**Check before spawning:** `$ctx->child($name)->isDefined()` returns `true` if the child is alive.

**See also:** [Exceptions — ActorNameExistsException](./exceptions.md#actornameexistsexception)

:::caution Consequence
Attempting to respawn a named actor before the previous instance stops crashes the parent actor's handler with `ActorNameExistsException`.
:::

---

### PoisonPill is FIFO, not preemptive

`PoisonPill` travels through the **user mailbox**, not the system queue. All messages enqueued before it are processed first. If you need immediate termination, use `$ctx->stop($ref)` from the parent — which sends an internal `Kill` and bypasses the user queue.

**See also:** [System messages — PoisonPill](./system-messages.md#poisonpill)

:::note
Sending `PoisonPill` followed immediately by more `tell()` calls means those later messages arrive after `PoisonPill` and go to dead letters, not to the actor.
:::

---

### Watching an already-stopped actor delivers Terminated immediately

Calling `$ctx->watch($ref)` on an actor that has already stopped sends a `Terminated` signal to the watcher's mailbox right away. There is no delay and no race — the runtime detects the stopped state and delivers immediately.

**See also:** [Lifecycle signals — Terminated](./lifecycle-signals.md#terminated)

:::caution Consequence
If your signal handler counts `Terminated` signals to know when all children are done, a race between `watch()` and the child stopping may count the same child twice. Store refs in a set and remove on `Terminated`.
:::

---

### $ctx->self() is only valid after PreStart

`$ctx->self()` returns the actor's own `ActorRef`. However, calling it during the behavior factory (inside `Behavior::setup()`) — before `PreStart` fires — returns a ref that may not yet be fully initialized in some runtime configurations. Capture `$ctx->self()` inside the `PreStart` signal handler or inside `Behavior::receive` handlers.

**See also:** [Lifecycle signals — PreStart](./lifecycle-signals.md#prestart)

:::caution Consequence
Capturing `$ctx->self()` during `Behavior::setup()` and passing it to external systems before `PreStart` fires may result in a ref that cannot receive messages.
:::

---

### reply() only works after ask

`$ctx->reply($msg)` sends a response to the actor that originated the current message. It throws `NoSenderException` when called on a message sent via `tell()`, because fire-and-forget messages carry no reply channel.

**Pattern:** Guard with `$ctx->sender()->isDefined()` or use separate handler branches for request-reply vs. fire-and-forget messages.

**See also:** [Exceptions — NoSenderException](./exceptions.md#nosenderexception)

:::caution Consequence
Calling `reply()` on a `tell()` message crashes the handler with `NoSenderException`, which the supervision strategy then handles (typically restarting the actor).
:::

---

### Ask to a dead actor times out, not throws

When `ActorRef::ask()` targets an actor that has stopped, the future does not reject immediately. The message goes to dead letters and the future waits for the full timeout duration before throwing `AskTimeoutException`. There is no "target is dead" fast-fail.

**Pattern:** Call `$ref->isAlive()` before `ask()` if you need a fast-fail on dead actors.

**See also:** [Exceptions — AskTimeoutException](./exceptions.md#asktimeoutexception)

:::caution Consequence
Code that asks a frequently-stopping actor will always wait the full timeout before failing, degrading throughput under heavy actor churn.
:::

---

### Stash capacity defaults to 100

`$ctx->stash()` buffers the current message for later processing. The stash buffer capacity is 100 messages by default. Exceeding it throws `StashOverflowException`.

**See also:** [Exceptions — StashOverflowException](./exceptions.md#stashoverflowexception)

:::caution Consequence
An actor that stashes indefinitely without calling `$ctx->unstashAll()` will crash on the 101st stashed message.
:::

---

### System messages preempt user messages

System messages (`Watch`, `Unwatch`, `Suspend`, `Resume`, `Kill`) are processed before any pending user message. A `Suspend` sent by the supervision system takes effect immediately, even if the user mailbox has thousands of messages waiting.

**See also:** [System messages](./system-messages.md)

:::note
This is correct supervision semantics — a crashing child must be suspended before the parent's decider runs. It also means `tell()` calls made just before a supervised crash may never be processed by the target.
:::

---

### Ask and Backpressure interact unexpectedly

`ActorRef::ask()` internally calls `tell()` with a reply-to ref. If the target's mailbox uses `OverflowStrategy::Backpressure`, the `ask()` call will suspend the caller's fiber until there is room in the mailbox — before the reply arrives. This is correct but may produce surprising latency when the mailbox is full.

**See also:** [Config — OverflowStrategy](./config.md#overflowstrategy)

:::caution Consequence
An `ask()` with a 1-second timeout to a backpressured actor may spend most of that timeout just waiting to enqueue the request message, leaving little time for the actor to process and reply.
:::

---

### Behavior::same() and BehaviorWithState::same() have different semantics

`Behavior::same()` — used in stateless handlers — means "keep the current behavior, no state change." `BehaviorWithState::same()` — used in stateful handlers — means "keep both the current behavior and the current state unchanged." They are not interchangeable: returning `Behavior::same()` from a `withState` handler discards the state; returning `BehaviorWithState::same()` from a stateless handler is a type error.

**See also:** [Core concepts — behaviors](../core-concepts/behaviors.md)

:::caution Consequence
Accidentally returning `Behavior::same()` from a `withState` handler is a Psalm type error (caught at analysis time), but if suppressed, it resets the state to the initial value on every message.
:::

---

### Factory closures must not capture by reference

Closures passed to `Props::fromFactory()` and `Props::fromStatefulFactory()` must not capture variables by reference (`use (&$var)`). The factory is called once per actor spawn and once per worker thread; a by-reference capture creates shared state across spawns.

**Enforce it:** `MutableClosureCaptureRule` reports `MutableClosureCapture`. See [Psalm rules](./psalm-rules.md#mutableclosurecapturerule).

:::caution Consequence
A by-reference capture in a factory closure may cause two actors to share a variable, producing non-deterministic behavior that is difficult to reproduce.
:::

---

### #[MessageType] is required for cross-worker tells

Messages sent via `WorkerActorRef::tell()` (cross-thread in the worker pool) must have `#[MessageType]` applied. The serializer uses the stable name to deserialize on the receiving worker. Omitting it produces a `NonSerializableRemoteMessage` Psalm error and a `MessageDeserializationException` at runtime.

**Enforce it:** `NonSerializableRemoteMessageRule`. See [Psalm rules](./psalm-rules.md#nonserializableremotemessagerule).

**See also:** [Attributes — #[MessageType]](./attributes.md#messagetype)

:::caution Consequence
A cross-worker tell without `#[MessageType]` either fails Psalm analysis or throws `MessageDeserializationException` at runtime on the receiving worker thread.
:::

---

### Init failure still emits PostStop

If `Behavior::setup()` throws during actor initialization, the actor never reaches `Running` state. However, `PostStop` is still emitted to the behavior's `onSignal` handler. This ensures cleanup logic registered in `PostStop` always runs, even after a failed start.

**See also:** [Lifecycle signals — PostStop](./lifecycle-signals.md#poststop)

:::note
Do not assume `PostStop` means the actor ran successfully. Check whether the actor reached `PreStart` first if you need to distinguish failed starts from normal stops.
:::

---

### ActorSystem is final — do not mock it

`ActorSystem` is declared `final`. It cannot be extended or mocked with Mockery/PHPUnit mocks. In tests, use `StepRuntime` with a real `ActorSystem` instance to control execution deterministically.

**See also:** [Persistence — testing](../persistence/testing.md)

:::tip
`StepRuntime::step()` processes one message at a time, giving you full control over actor execution order without mocking.
:::

---

### EntityRefFactory caches refs — single-writer applies per ref

`EntityRefFactory` caches `EntityBehavior` actor refs by entity ID. Each cached ref is the single writer for its entity. If you create multiple `EntityRefFactory` instances pointing at the same entity IDs (e.g., in tests without cleanup), two refs may attempt to write to the same stream, triggering `WriterConflictException`.

**See also:** [Exceptions — WriterConflictException](./exceptions.md#writerconflictexception)

:::caution Consequence
Tests that create fresh `EntityRefFactory` instances without clearing the event store will encounter writer conflicts on the second run.
:::

---

### writerId scope is per ActorSystem instance

Each `ActorSystem` instance generates a unique ULID `writerId` on creation (accessible via `$system->writerId()`). Two calls to `ActorSystem::create()` produce two different writer IDs. The persistence layer uses this to detect single-writer violations.

**See also:** [Single-writer guarantee](../persistence/single-writer.md)

:::caution Consequence
Restarting an `ActorSystem` (e.g., after a crash) creates a new `writerId`. If the old system's events are still in the store with the old ID, `ReplayFilter` in `Fail` mode throws `WriterConflictException` on the first recovery.
:::

---

### dequeueBlocking holds the fiber for the full timeout

`Mailbox::dequeueBlocking(Duration $timeout)` suspends the calling fiber until a message arrives or the timeout elapses. With a long timeout (e.g., `Duration::seconds(60)`), the fiber is parked for up to 60 seconds when the mailbox is empty. This is correct for the actor message loop but problematic if called directly in application code.

**See also:** [Reference — MailboxTimeoutException](./exceptions.md#mailboxtimeoutexception)

:::caution Consequence
Calling `dequeueBlocking()` with a long timeout outside the actor runtime holds a fiber slot occupied for the duration, reducing concurrency headroom.
:::

---

### ReceiveTimeout resets on user messages only

`$ctx->setReceiveTimeout(Duration)` starts an idle timer. The timer resets when a **user message** is processed. System messages (`Suspend`, `Resume`, `Watch`) and lifecycle signals (`PreStart`, `PostStop`) do not reset the timer.

**See also:** [Lifecycle signals — ReceiveTimeout](./lifecycle-signals.md#receivetimeout)

:::note
An actor that only receives system messages while waiting for user messages will still fire `ReceiveTimeout` after the configured duration, even if the actor appears "busy" from a system-message perspective.
:::

---

### Anonymous actor names are ephemeral — do not persist them

`$system->spawnAnonymous(Props)` generates a unique name for the actor. That name is not stable across restarts or deployments. Never persist an anonymous actor's path to a database or send it to an external system as a durable identifier.

**Pattern:** Give long-lived, externally-addressable actors explicit names via `$system->spawn(Props, 'my-stable-name')`.

:::caution Consequence
Persisting an anonymous actor path and looking it up after a restart will find no actor — the runtime generates a new name on each spawn.
:::

---

## See also

- [Exception catalog](./exceptions.md) — full exception reference with recovery guidance
- [Lifecycle signals](./lifecycle-signals.md) — `PreStart`, `PostStop`, `Terminated`, `ChildFailed`, `ReceiveTimeout`
- [System messages](./system-messages.md) — `PoisonPill`, `Kill`, `Suspend`/`Resume`
- [Psalm rules](./psalm-rules.md) — static analysis enforcement for several of these constraints
