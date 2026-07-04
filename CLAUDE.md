# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Critical Rules

- **Never add `Co-Authored-By: Claude` to commits.** The user does not want Claude attribution in commit messages.
- **Always use Docker for everything.** No local PHP is installed. All commands (tests, linting, Psalm, Composer) must run through `docker compose exec php` or via `make` targets. Never suggest running `php`, `composer`, `vendor/bin/*` directly on the host.
- **GrumPHP pre-commit hooks run via Docker.** Both the `pre-commit` and `commit-msg` hooks execute through `docker compose exec -T php`. If a hook fails with `env: php: No such file or directory`, the hook needs to be updated to use Docker.

## Project Overview

Nexus is a production-grade typed actor system for PHP 8.5+, bringing Akka/OTP patterns to PHP. Write actor code once, run it on PHP Fibers (development/testing) or Swoole (production). The project is a monorepo with 34 packages under `packages/`, each published independently to Packagist.

## Development Environment

```bash
make build          # Build Docker images (php, php-fiber, php-swoole)
make install        # composer install inside container
make up / make down # Start/stop containers
make shell          # Interactive bash in PHP container
```

**Docker services** (`compose.yaml`):
- `php` — Full environment (Xdebug + Swoole) for development
- `php-fiber` — Fiber-only for unit/integration tests and CI
- `php-swoole` — Swoole-only for Swoole and worker-pool tests and CI

**Dockerfile targets** (`docker/Dockerfile`):
- `php-fiber` — PHP 8.5 CLI + Xdebug
- `php-swoole` — PHP 8.5 CLI + Swoole 6.0
- `php-full` — Both Xdebug and Swoole

## Commands

### Testing

```bash
make test                # All test suites
make test-unit           # Unit tests only (all packages)
make test-fiber          # Fiber integration tests
make test-swoole         # Swoole integration tests (uses php-swoole container)
make test-cluster        # Cluster integration tests (uses php-swoole container)
make test-persistence    # Persistence unit + integration tests
make test-serialization  # Serialization integration tests
make mutation            # Infection mutation testing (min 80% MSI, 90% covered)

# Single test file
docker compose exec php vendor/bin/phpunit packages/nexus-core/tests/Unit/Actor/ActorSystemTest.php

# Single test method
docker compose exec php vendor/bin/phpunit --filter=testMethodName packages/nexus-core/tests/Unit/Actor/ActorSystemTest.php

# Performance benchmarks
docker compose exec php vendor/bin/phpunit --testsuite=performance --filter=Fiber
docker compose exec php-swoole vendor/bin/phpunit --testsuite=performance --filter="Swoole|Cluster"
```

### Linting & Static Analysis

```bash
make psalm      # Psalm type checking (level 1 strict)
make phpcs      # PHPCS standards check
make phpcbf     # Auto-fix PHPCS violations
make cs         # PHP-CS-Fixer dry-run (PER-CS2.0)
make cs-fix     # PHP-CS-Fixer auto-fix
```

### Pre-commit Hooks

GrumPHP runs four checks on every commit via Docker (`grumphp.yml`):
1. **PHP-CS-Fixer** — Code style (PER-CS2.0:risky)
2. **PHPCS** — Coding standards (Slevomat extensions)
3. **Psalm** — Static type analysis (level 1)
4. **PHPUnit** — Unit test suite (always runs)

All four must pass. The hooks execute through `docker compose exec -T php`.

## Code Style Rules

- **PER-CS2.0** coding standard with Slevomat extensions
- Arrays with string keys **must be sorted alphabetically** (`SlevomatCodingStandard.Arrays.AlphabeticallySortedByKeys`)
- Ternary operators **must be multi-line** (`SlevomatCodingStandard.ControlStructures.RequireMultiLineTernaryOperator`)
- **Blank line required before** `if`/`for`/`foreach`/`while`/`switch`/`try` blocks (`SlevomatCodingStandard.ControlStructures.BlockControlStructureSpacing`)
- All classes are `final` by default, all value objects are `readonly`
- Ordered imports: class, function, const (each alphabetical)
- Trailing commas in all multiline contexts
- Psalm runs in strict mode (level 1) — explicit `(float)` casts don't satisfy `InvalidOperand` for int/float mixing; use `@psalm-suppress InvalidOperand` on the **method docblock** instead

## Architecture

### Design Philosophy

1. **Location Transparency** — Actor code is identical whether sending to local or remote actors. `ActorRef<T>` abstracts `LocalActorRef` (in-process), `WorkerActorRef` (cross-thread within a worker pool), and future remote node refs.
2. **Immutability-First** — All core types use `readonly` + `final`. Behaviors, Props, Duration, Envelope, ActorPath, SupervisionStrategy are value objects. Mutation returns new instances via `clone()`.
3. **Generic Type Safety** — Heavy use of Psalm generics: `ActorRef<T>`, `Behavior<T>`, `Props<T>`, `ActorContext<T>`. Psalm Level 1 enforces type safety at compile time.
4. **Functional Composition** — Behaviors are closures, enabling composition. `Behavior::receive(fn(...) => ...)`, `Behavior::setup(fn(...) => ...)`.
5. **Pluggable Runtimes** — `Runtime` interface abstracts concurrency. Same actor code runs on Fiber (cooperative multitasking), Swoole (coroutines + true async I/O), or Step (deterministic testing).
6. **Supervision Trees** — Parent actors supervise children with configurable strategies (restart, stop, escalate, backoff). Akka-style "let it crash" philosophy.
7. **Fail-Fast** — Exceptions in handlers are caught by supervision. Dead letters capture undeliverable messages. Clear exception hierarchy with `NexusException` base.

### Package Dependency Graph

```
nexus-core (no dependencies — foundational)
├── nexus-runtime-fiber    → Core only
├── nexus-runtime-swoole   → Core only
├── nexus-runtime-step     → Core only (deterministic test runtime)
├── nexus-app              → Core only (PSR-11 bootstrap)
├── nexus-serialization    → Core only
│   ├── nexus-persistence  → Core, Serialization
│   │   ├── nexus-persistence-dbal     → Persistence, Core, Serialization
│   │   └── nexus-persistence-doctrine → Persistence, Core, Serialization
│   └── nexus-messenger        → Core, Runtime, Serialization, Observability (+ symfony/messenger, psr/event-dispatcher)
│       ├── nexus-messenger-console → Core, Messenger, Runtime, Serialization, Observability (+ symfony/console)
│       └── nexus-messenger-console-swoole → Core, Messenger, MessengerConsole, Runtime, RuntimeSwoole, Serialization, WorkerPool, WorkerPoolSwoole (+ opis/closure)
├── nexus-cluster          → Core only (remote contracts)
├── nexus-worker-pool      → Core, Runtime
│   └── nexus-worker-pool-swoole → WorkerPool, Core, RuntimeSwoole
├── nexus-psalm            → (standalone Psalm plugin)
└── nexus-observability    → Core only (OTel contracts + no-op impls — foundational)
    ├── nexus-observability-otel       → Observability, OTel SDK (concrete OTel backend)
    ├── nexus-observability-actor      → Observability, Core (ActorSystem metrics)
    ├── nexus-observability-http       → Observability, nexus-http (HTTP tracing + metrics)
    ├── nexus-observability-persistence → Observability, nexus-persistence (store tracing)
    ├── nexus-observability-worker-pool → Observability, nexus-worker-pool (transport tracing)
    ├── nexus-observability-doctrine   → Observability, nexus-doctrine-dbal/-orm (DBAL/ORM metrics)
    ├── nexus-observability-logger     → Observability, PSR-3 (trace-correlation log processor)
    └── nexus-observability-swoole     → Observability, nexus-runtime-swoole (Swoole admin metrics)
```

Enforced by Deptrac (`deptrac.yaml`). Core must never depend on anything else.

### Core Actor Model (nexus-core/src/)

**`Behavior<T>`** (`Actor/Behavior.php`) — Immutable behavior definition. The heart of the actor model. Returns from handlers to represent what the actor does next.
- `Behavior::receive(fn(ActorContext<T>, T): Behavior<T>)` — Stateless message handler
- `Behavior::withState($initialState, fn(ActorContext<T>, T, S): BehaviorWithState<T,S>)` — Stateful handler
- `Behavior::setup(fn(ActorContext<T>): Behavior<T>)` — Factory that resolves behavior at startup
- `Behavior::same()` — Keep current behavior (no change)
- `Behavior::stopped()` — Stop the actor
- `Behavior::unhandled()` — Message not handled (routes to dead letters)
- `->onSignal(fn(ActorContext<T>, Signal): Behavior<T>)` — Attach lifecycle signal handler

**`BehaviorWithState<T, S>`** (`Actor/BehaviorWithState.php`) — Result type for stateful handlers:
- `BehaviorWithState::next($newState)` — Same behavior, new state
- `BehaviorWithState::same()` — Keep both behavior and state
- `BehaviorWithState::stopped()` — Stop the actor
- `BehaviorWithState::withBehavior($behavior, $state)` — Switch behavior and state

**`ActorRef<T>`** (`Actor/ActorRef.php`) — Type-safe reference to an actor:
- `tell(object $message): void` — Fire-and-forget
- `ask(callable(ActorRef<R>): T, Duration $timeout): R` — Request-response with timeout
- `path(): ActorPath` / `isAlive(): bool`
- Implementations: `LocalActorRef<T>` (enqueues to mailbox, also implements `BackpressureCapable::offer(object): EnqueueResult` for delivery-feedback integrations), `WorkerActorRef<T>` (sends `Envelope` directly via `WorkerTransport` — no serializer), `DeadLetterRef` (null object)

**`ActorContext<T>`** (`Actor/ActorContext.php`) — Runtime context passed to handlers:
- `self(): ActorRef<T>` / `parent(): Option<ActorRef>` / `sender(): Option<ActorRef>`
- `spawn(Props<C>, string $name): ActorRef<C>` — Spawn child actor
- `stop(ActorRef $child)` / `child(string)` / `children()`
- `watch(ActorRef)` / `unwatch(ActorRef)` — Death watch
- `scheduleOnce(Duration, object): Cancellable` / `scheduleRepeatedly(Duration, Duration, object): Cancellable`
- `stash(): void` / `unstashAll(): void` — Message buffering
- `log(): LoggerInterface` — PSR-3 logger
- `tracer(): TracerInterface` / `meter(): MeterInterface` / `currentSpan(): SpanInterface` — Custom telemetry (no-op when observability is disabled; provided by `nexus-observability`)

**`Props<T>`** (`Actor/Props.php`) — Immutable spawn configuration:
- `Props::fromBehavior(Behavior<T>)` — Closure-based actor
- `Props::fromFactory(fn(): ActorHandler<T>)` — Class-based actor
- `Props::fromStatefulFactory(fn(): StatefulActorHandler<T,S>)` — Stateful class actor
- `Props::fromContainer(ContainerInterface, string $class)` — PSR-11 DI
- `->withMailbox(MailboxConfig)` / `->withSupervision(SupervisionStrategy)`

**`ActorSystem`** (`Actor/ActorSystem.php`) — Entry point:
- `ActorSystem::create(string $name, Runtime, ?Clock, ?Logger, ?EventDispatcher)`
- `spawn(Props<T>, string $name): ActorRef<T>` — Spawn a child actor. If a child with the given name has already terminated, it is pruned automatically and a new actor is spawned in its place. If a **live** child with that name already exists, throws `ActorNameExistsException`. This enables passivation patterns like `EntityRefFactory::of($id)` where dead actors are transparently respawned. / `spawnAnonymous(Props<T>): ActorRef<T>`
- `run(): void` — Start event loop (blocking)
- `shutdown(Duration $timeout): void` — Deadline-driven graceful shutdown: marks system stopping, broadcasts `PoisonPill` to root children, yields cooperatively until drained or deadline, force-closes survivors' mailboxes, signals runtime shutdown. Coroutine-safe (`Mailbox::enqueue` wraps `Channel::push` when called outside a coroutine). On Swoole thread mode, `SwooleThreadServer` flips a `Thread\Atomic` from `BeforeShutdown` so per-worker watchdog coroutines invoke this before Swoole's reactor exit timeout.
- `deadLetters(): DeadLetterRef`

**`ActorCell<T>`** (`Actor/ActorCell.php`) — Internal engine implementing `ActorContext<T>`. Manages the behavior state machine, children map, watchers, stash buffer, and message processing. States: `New → Starting → Running → {Suspended, Stopping} → Stopped`.

### Actor Definition Patterns

**Closure-based (simplest):**
```php
$behavior = Behavior::receive(static fn(ActorContext $ctx, object $msg) => match(true) {
    $msg instanceof Greet => /* handle */ Behavior::same(),
    default => Behavior::unhandled(),
});
$ref = $system->spawn(Props::fromBehavior($behavior), 'greeter');
```

**Stateful closure-based:**
```php
$behavior = Behavior::withState(0, static fn(ActorContext $ctx, object $msg, int $count) => match(true) {
    $msg instanceof Increment => BehaviorWithState::next($count + 1),
    $msg instanceof GetCount => /* reply */ BehaviorWithState::same(),
});
```

**Setup-based (lazy initialization):**
```php
$behavior = Behavior::setup(static function (ActorContext $ctx): Behavior {
    $child = $ctx->spawn(Props::fromBehavior($childBehavior), 'child');
    return Behavior::receive(static fn($ctx, $msg) => /* use $child */ Behavior::same());
});
```

**Class-based (`ActorHandler`):**
```php
class GreeterActor implements ActorHandler {
    public function handle(ActorContext $ctx, object $message): Behavior {
        return Behavior::same();
    }
}
$ref = $system->spawn(Props::fromFactory(fn() => new GreeterActor()), 'greeter');
```

**Class-based with lifecycle (`AbstractActor`):**
```php
class MyActor extends AbstractActor {
    public function onPreStart(ActorContext $ctx): void { /* init */ }
    public function handle(ActorContext $ctx, object $message): Behavior { return Behavior::same(); }
    public function onPostStop(ActorContext $ctx): void { /* cleanup */ }
}
```

**Stateful class-based (`StatefulActorHandler`):**
```php
class CounterActor implements StatefulActorHandler {
    public function initialState(): int { return 0; }
    public function handle(ActorContext $ctx, object $msg, int $state): BehaviorWithState {
        return BehaviorWithState::next($state + 1);
    }
}
$ref = $system->spawn(Props::fromStatefulFactory(fn() => new CounterActor()), 'counter');
```

### Messages and Signals

**User messages** — `readonly class` objects by convention (Psalm plugin enforces `readonly`):
```php
readonly class Greet { public function __construct(public string $name, public ActorRef $replyTo) {} }
readonly class Greeted { public function __construct(public string $greeting) {} }
```

**System messages** (`Core\Message\`) — Internal control, processed before user messages:
- `PoisonPill` — Graceful shutdown (stops children, delivers PostStop, closes mailbox)
- `Watch(ActorRef $watcher)` / `Unwatch(ActorRef $watcher)` — Death watch registration
- `Suspend` / `Resume` — Pause/resume processing
- `DeadLetter` — Undeliverable message wrapper

**Lifecycle signals** (`Core\Lifecycle\`) — Handled via `behavior->onSignal(...)`:
- `PreStart` — After actor starts, before first message
- `PostStop` — During shutdown, after children stopped
- `Terminated(ActorRef)` — Watched actor terminated
- `ChildFailed(ActorRef, Throwable)` — Child threw exception
- `ReceiveTimeout` — No user message received within the duration set by `$ctx->setReceiveTimeout(Duration)`. Resets on every user message; system messages do not reset. Cancel with `$ctx->setReceiveTimeout(null)`.

### Mailbox System

**`Mailbox`** interface (`Mailbox/Mailbox.php`):
- `enqueue(Envelope): EnqueueResult` — Returns `Accepted`, `Dropped`, or `Backpressured`
- `dequeue(): Option<Envelope>` — Non-blocking poll
- `dequeueBlocking(Duration $timeout): Envelope` — Blocking wait (fiber suspends)
- `close()` — Close and wake all waiters

**`MailboxConfig`** — `bounded(capacity, strategy)` or `unbounded()`
**`OverflowStrategy`** — `DropNewest`, `DropOldest`, `Backpressure`, `ThrowException`
**`Envelope`** — Immutable wrapper: `message`, `sender` (ActorPath), `target` (ActorPath), `metadata`

### Supervision

**`SupervisionStrategy`** (`Supervision/SupervisionStrategy.php`):
- `SupervisionStrategy::oneForOne(maxRetries, window, decider)` — Restart only failed child
- `SupervisionStrategy::allForOne(maxRetries, window, decider)` — Restart all children
- `SupervisionStrategy::exponentialBackoff(initialBackoff, maxBackoff, maxRetries, multiplier, decider)` — Restart with delay
- Custom decider: `fn(Throwable): Directive` where `Directive` is `Restart|Stop|Resume|Escalate`

### Runtime Abstraction

**`Runtime`** interface (`Runtime/Runtime.php`):
- `createMailbox(MailboxConfig): Mailbox`
- `spawn(callable $actorLoop): string` — Spawn fiber/task for actor message loop
- `scheduleOnce(Duration, callable): Cancellable` / `scheduleRepeatedly(Duration, Duration, callable): Cancellable`
- `yield()` / `sleep(Duration)` — Cooperative scheduling
- `run()` / `shutdown(Duration)` / `isRunning()`

**FiberRuntime** — Each actor runs in its own PHP Fiber. Fibers suspend when waiting for messages (`dequeueBlocking` suspends fiber), resume when mailbox has data. `FiberScheduler` manages timer-based callbacks via priority queue.

**SwooleRuntime** — Uses Swoole coroutines and channels. True async I/O, multi-process scaling.

**StepRuntime** — Deterministic testing. `step()` processes one message, `drain()` processes all queued.

### Duration Value Object

**`Duration`** (`Duration.php`) — Nanosecond-precision immutable:
- `Duration::seconds(n)` / `millis(n)` / `micros(n)` / `nanos(n)` / `zero()`
- Arithmetic: `plus()`, `minus()`, `multipliedBy()`, `dividedBy()`
- Comparison: `equals()`, `isGreaterThan()`, `isLessThan()`, `compareTo()`
- Conversion: `toNanos()`, `toMillis()`, `toSeconds()`, `toSecondsFloat()`

### Persistence

**Event Sourcing** — Commands produce events, events replay to rebuild state:
```php
EventSourcedBehavior::create($persistenceId, $emptyState, $commandHandler, $eventHandler)
    ->withEventStore($eventStore)
    ->withSnapshotStore($snapshotStore)
    ->withSnapshotStrategy(SnapshotStrategy::everyN(10))
    ->withRetention(RetentionPolicy::snapshotAndEvents(3, deleteEventsTo: true))
    ->withReplayFilter(ReplayFilterMode::Fail)
    ->toBehavior()
```

- `PersistenceId::of('EntityType', 'entity-id')` — Unique identity
- `Effect::persist(...$events)` / `Effect::none()` / `Effect::stash()` / `Effect::stop()` / `Effect::reply($to, $msg)`
- Side effects: `->thenRun(fn($state) => ...)` / `->thenReply($to, fn($state) => $msg)`
- `PersistenceEngine` handles startup recovery (load snapshot → replay events → ready)
- Stores: `InMemoryEventStore`, `DbalEventStore`, `DoctrineEventStore`

**Durable State** — Simpler model, persists full state snapshots (no event history):
```php
DurableStateBehavior::create($persistenceId, $emptyState, $commandHandler)
    ->withStateStore($stateStore)
    ->toBehavior()
```
- `DurableEffect::persist($newState)` / `none()` / `stash()` / `stop()` / `reply()`
- `DurableStateEngine` handles startup recovery (load latest state → ready)

**Single-Writer Principle** — Each `ActorSystem` has a unique ULID (`$system->writerId(): Ulid`) stamped on every persisted envelope. `WriterConflictException` is thrown when a store detects a different writer. `ReplayFilter` validates writer consistency during event replay with configurable modes: `Fail` (throw on interleave), `Warn` (log warning), `RepairByDiscardOld` (keep only latest writer's events), `Off` (skip filtering).

### Worker Pool (nexus-worker-pool)

- `WorkerNode` — Per-worker coordinator. Routes via hash ring, handles transport envelopes, manages local actor refs.
- `ConsistentHashRing` — Maps actor names to worker IDs via CRC32 with 150 virtual nodes.
- `WorkerActorRef<T>` — Cross-worker actor reference. Sends `Envelope` objects directly via `WorkerTransport` — no serializer involved.
- `WorkerTransport` interface — `send(int $targetWorker, Envelope $envelope): void` / `listen(callable): void`. Implementations: `InMemoryWorkerTransport` (tests), `ThreadQueueTransport` (Swoole threads).
- `WorkerDirectory` interface — Maps actor paths to worker IDs. Implementations: `InMemoryWorkerDirectory` (tests), `ThreadMapDirectory` (Swoole threads).
- `WorkerPoolConfig` — `WorkerPoolConfig::withThreads(int $workerCount): self`.
- `WorkerStartHandler` interface — Implement to set up actors when a worker thread starts.

### Worker Pool Swoole (nexus-worker-pool-swoole)

**Prerequisites:** Requires ZTS (Zend Thread Safety) PHP 8.5+ and Swoole compiled with `--enable-swoole-thread` (Swoole 6.0+).

- `WorkerPoolApp` — Abstract base: extend and override `configure(WorkerNode $node)`, call `static::run(WorkerPoolConfig $config)` to boot the pool.
- `WorkerPoolBootstrap` — Creates N worker threads via `Swoole\Thread\Pool`. Shares a `Thread\Map` directory and one `Thread\Queue` inbox per worker.
- `WorkerRunnable` — Thread entrypoint (`Swoole\Thread\Runnable`). Atomically claims a worker ID, boots `ActorSystem` + `SwooleRuntime`, calls `WorkerStartHandler::onWorkerStart()`.
- `ThreadQueueTransport` — Thread-safe transport backed by one `Swoole\Thread\Queue` per worker. Adaptive-poll coroutine loop with backoff: 0µs → 100µs → 1ms → 10ms.
- `ThreadMapDirectory` — Thread-safe actor directory backed by shared `Swoole\Thread\Map`.

### Cluster — Remote Contracts (nexus-cluster)

- `NodeAddress` — Value object for multi-machine node addressing (`cluster/datacenter/application/node`).
- `ClusterTransport` interface — `send(NodeAddress $target, string $data): void`. For future TCP inter-node transport.
- `NodeDirectory` interface — Maps actor paths to `NodeAddress` for multi-machine routing.
- `NodeHashRing` — Consistent hash ring mapping actor names to `NodeAddress` instances.

### Messenger Bridge (nexus-messenger)

Two-way bridge to standalone `symfony/messenger` transports (no framework-bundle, no console).

- `MessengerActorRef<T>` — `ActorRef` backed by a Messenger `SenderInterface`; `tell()` publishes to the transport, `ask()` throws `UnsupportedOperationException` (v1). Messages need `#[MessageType]` (Psalm-enforced).
- `MessengerGateway` — explicit `publish(object, array $stamps = [])` egress service.
- `ReceiverActor` — supervised poll→route→ack loop per `ReceiverInterface`. Acks only on `EnqueueResult::Accepted` (via the core `BackpressureCapable` seam); backpressured/dropped enqueues are not acked so the broker redelivers (at-least-once). Unroutable messages: `reject()` by default, dead-letters opt-in (`ReceiverActorConfig`).
- `MessageRouter` — pluggable inbound routing: `MapMessageRouter` (class → ref, default), `StampMessageRouter` (TargetActorPathStamp → ref; cluster seam).
- `NexusMessengerSerializer` — Messenger `SerializerInterface` backed by a Nexus `MessageSerializer` + `TypeRegistry`; bridge stamps travel as headers.
- `LifecycleWatchdog` — worker recycling: triggers graceful `ActorSystem::shutdown()` on memory/uptime/message-count thresholds (`LifecycleThresholds`).
- `MessengerBridge` — static wiring facade: `producer()`, `gateway()`, `receiverProps()`, `spawnReceivers(ActorSystem, int $count, string $namePrefix, ...)` (N competing in-process consumers over one transport), `watchdogProps()`.

### Messenger Console (nexus-messenger-console)

Symfony Console runners — keeps `nexus-messenger` free of `symfony/console`.

- `ConsumeCommand` (`nexus:messenger:consume`) — boots `ActorSystem::create()`, calls `MessengerBridge::spawnReceivers()`, optionally spawns `LifecycleWatchdog` (wired as `$processedListener`) when any limit option is present. No watchdog when no limits. Options: `--receivers|-r` (int, default 1), `--limit`, `--memory-limit` (e.g. `128M`), `--time-limit` (seconds), `--poll-interval` (ms, default 100), `--dead-letters`. Implements `SignalableCommandInterface` (SIGINT/SIGTERM → graceful shutdown).
- `ProduceCommand` (`nexus:messenger:produce`) — resolves a type name via `TypeRegistry::classForName()`, deserializes a JSON body via `MessageSerializer::deserialize()`, publishes N messages via `MessengerBridge::gateway()`. Args: `type`, `body`. Option: `--count|-c`.
- `MemoryLimit` — parses human-readable memory strings (`128M`, `1G`, K/M/G suffixes, case-insensitive) to bytes; throws `InvalidArgumentException` on invalid input.
- `ConsumerSetup` interface — `setup(ActorSystem): MessageRouter`; implement to defer actor spawning until `ConsumeCommand` boots the system.
- `CallbackConsumerSetup` — closure-based `ConsumerSetup` implementation.

### Messenger Console Swoole (nexus-messenger-console-swoole)

Swoole thread-pool adapter for `nexus-messenger-console`. Requires `ext-swoole >= 6.2.1`.

- `ThreadedConsumerBootstrap` — interface extending `ConsumerSetup` with `receiver(): ReceiverInterface`; implement and pass the class-string to `ThreadedConsumeCommand`. The pool instantiates it fresh per thread — no cross-thread object sharing.
- `ThreadedConsumeCommand` (`nexus:messenger:consume-threads`) — validates `$bootstrapClass is_a ThreadedConsumerBootstrap`, builds a `static` opis-serialized configure closure capturing only scalars + the class-string, then calls `WorkerPoolBootstrap::create(WorkerPoolConfig::withThreads($n)->withSystemNamePrefix('messenger-consumer'))->withSerializedConfigure(...)->run()`. Options: `--threads|-t` (default 2), `--receivers|-r` (default 1 per thread), `--limit`, `--memory-limit`, `--time-limit`, `--poll-interval` (ms, default 100), `--dead-letters`. All limit options are **per-thread** — each thread has its own `LifecycleWatchdog`. No `SignalableCommandInterface` in v1; main thread blocks in `Pool::start()`, stop via SIGTERM.
- Thread-boundary rule: the configure closure must be `static` and capture ONLY scalars + class-strings. Live objects (logger, transport, router) cannot cross thread boundaries; each thread constructs its own via `new $bootstrapClass()`.

### Application Bootstrap (nexus-app)

```php
NexusApp::create('my-app')
    ->actor('orders', Props::fromBehavior($orderBehavior))
    ->actor('payments', Props::fromFactory(fn() => new PaymentActor()))
    ->onStart(function(ActorSystem $system) { /* setup */ })
    ->run(new FiberRuntime());
```

### Serialization (nexus-serialization)

- `MessageSerializer` — `serialize(object): string` / `deserialize(string, string $type): object`
- `EnvelopeSerializer` — Wraps message serializer, handles envelope structure
- Implementations: `PhpNativeSerializer` (PHP serialize/unserialize), Valinor-based mapper
- `DefaultEnvelopeSerializer` — JSON envelope with delegated message serialization

### Psalm Plugin (nexus-psalm)

Custom Psalm plugin with 8 hooks for actor-specific validation:
1. **ReadonlyMessageRule** — Messages passed to `tell()` must be `readonly` classes
2. **MutableActorStateRule** — `ActorHandler`/`StatefulActorHandler` properties must be `readonly`
3. **NonSerializableRemoteMessageRule** — `WorkerActorRef::tell()` messages need `#[MessageType]` attribute
4. **BlockingCallInHandlerRule** — Detects blocking calls (`sleep`, `file_get_contents`, `curl_exec`, etc.) in handlers
5. **MutableClosureCaptureRule** — `Props::fromFactory()` closures must not capture by reference (`&$var`)
6. **PropsReturnTypeProvider** — Infers generic types for `Props::from*()` methods
7. **CloneWithReturnTypeProvider** — Type inference for `clone()` operations
8. **UntypedActorRefInjectionRule** (+ `UntypedActorRefPropertyRule`) — Injected `ActorRef` params/properties must declare a concrete message type (`ActorRef<T>`); bare `ActorRef` and `ActorRef<object>` flagged, `DeadLetterRef` + configured excludes exempt, by-design internals exempted via psalm.xml issueHandlers

### Exception Hierarchy

All exceptions extend `NexusException` (abstract, extends `RuntimeException`):
- `ActorInitializationException` — Setup/startup failed
- `ActorNameExistsException` — Duplicate child name in spawn
- `AskTimeoutException` — Request-response timeout
- `MailboxClosedException` — Enqueue/dequeue on closed mailbox
- `MailboxOverflowException` — Bounded queue at capacity with ThrowException strategy
- `InvalidActorStateTransition` — Invalid lifecycle state change
- `InvalidActorPathException` — Malformed actor path
- `MaxRetriesExceededException` — Supervision retry limit hit

## Test Organization

- Unit tests: `packages/*/tests/Unit/`
- Integration tests: `tests/Integration/{Fiber,Swoole,Step,Serialization,WorkerPool,Persistence}/`
- Performance tests: `tests/Performance/`
- Test utilities: `packages/nexus-core/tests/Support/` (TestRuntime, TestMailbox, TestClock — included in `phpunit.xml` `<source>` for coverage)
- PHPUnit attributes: `#[CoversClass(ClassName::class)]` on test classes, `#[CoversNothing]` for interface tests, `#[Test]` on methods

### Integration Test Pattern

All Fiber integration tests follow this pattern:
```php
$runtime = new FiberRuntime();
$system = ActorSystem::create('test', $runtime);
$ref = $system->spawn(Props::fromBehavior($behavior), 'actor-name');
$ref->tell(new MyMessage());
$runtime->scheduleOnce(Duration::millis(500), fn() => $system->shutdown(Duration::seconds(1)));
$system->run(); // blocks until shutdown
self::assertSame($expected, $captured);
```

Swoole tests differ: messages must be sent inside `scheduleOnce()` callbacks (Swoole channels require coroutine context).

## CI Pipeline

**ci.yml** — Runs on push to main and PRs (Docker-based):
1. `build-images` — Build php-fiber and php-swoole Docker images (cached via GHA)
2. `lint` — PHPCS + PHP-CS-Fixer
3. `static-analysis` — Psalm + Deptrac (with `php -d error_reporting="E_ALL & ~E_DEPRECATED"` for deptrac PHP 8.5 compat)
4. `unit-tests` — Unit tests with coverage + Swoole unit tests + Psalm plugin tests + coverage-guard (90% method coverage minimum)
5. `integration-fiber` — Fiber, Serialization, Step, Persistence integration tests
6. `integration-swoole` — Swoole + Worker Pool integration tests
7. `mutation-testing` — Infection on PRs only (`continue-on-error: true` for PHPUnit 13 compat)

**split.yml** — Splits each package to its own GitHub repo via splitsh-lite (gated on CI success)

**deploy-docs.yml** — Docusaurus deployment to GitHub Pages (gated on CI success)

## Monorepo Package Updates

When modifying dev dependency versions (e.g., PHPUnit), update both the root `composer.json` AND all 15 `packages/*/composer.json` files — each package is published independently to Packagist.

## PSR Integration

- **PSR-3** (LoggerInterface) — Actor logging via `$ctx->log()`
- **PSR-11** (ContainerInterface) — Actor resolution via `Props::fromContainer()`
- **PSR-14** (EventDispatcherInterface) — System-level event dispatching
- **PSR-20** (ClockInterface) — Time abstraction (TestClock for deterministic tests)

# CLAUDE.md

Behavioral guidelines to reduce common LLM coding mistakes. Merge with project-specific instructions as needed.

**Tradeoff:** These guidelines bias toward caution over speed. For trivial tasks, use judgment.

## 1. Think Before Coding

**Don't assume. Don't hide confusion. Surface tradeoffs.**

Before implementing:
- State your assumptions explicitly. If uncertain, ask.
- If multiple interpretations exist, present them - don't pick silently.
- If a simpler approach exists, say so. Push back when warranted.
- If something is unclear, stop. Name what's confusing. Ask.

## 2. Simplicity First

**Minimum code that solves the problem. Nothing speculative.**

- No features beyond what was asked.
- No abstractions for single-use code.
- No "flexibility" or "configurability" that wasn't requested.
- No error handling for impossible scenarios.
- If you write 200 lines and it could be 50, rewrite it.

Ask yourself: "Would a senior engineer say this is overcomplicated?" If yes, simplify.

## 3. Surgical Changes

**Touch only what you must. Clean up only your own mess.**

When editing existing code:
- Don't "improve" adjacent code, comments, or formatting.
- Don't refactor things that aren't broken.
- Match existing style, even if you'd do it differently.
- If you notice unrelated dead code, mention it - don't delete it.

When your changes create orphans:
- Remove imports/variables/functions that YOUR changes made unused.
- Don't remove pre-existing dead code unless asked.

The test: Every changed line should trace directly to the user's request.

## 4. Goal-Driven Execution

**Define success criteria. Loop until verified.**

Transform tasks into verifiable goals:
- "Add validation" → "Write tests for invalid inputs, then make them pass"
- "Fix the bug" → "Write a test that reproduces it, then make it pass"
- "Refactor X" → "Ensure tests pass before and after"

For multi-step tasks, state a brief plan:
```
1. [Step] → verify: [check]
2. [Step] → verify: [check]
3. [Step] → verify: [check]
```

Strong success criteria let you loop independently. Weak criteria ("make it work") require constant clarification.

---

**These guidelines are working if:** fewer unnecessary changes in diffs, fewer rewrites due to overcomplication, and clarifying questions come before implementation rather than after mistakes.
