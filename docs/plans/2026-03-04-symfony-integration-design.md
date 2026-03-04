# Symfony Integration Design

**Date:** 2026-03-04
**Status:** Approved
**Scope:** V1

---

## Goals

Bring Nexus actors into Symfony 8+ applications with zero friction:

- Any Symfony service can inject `ActorRef<T>` or `ActorSystem`
- Any service becomes an actor via a single attribute — no interface required
- HTTP controllers remain unchanged — actors are invisible infrastructure
- Swoole-only: production-grade concurrency, no Fiber mode
- Full test support without `ext-swoole`

---

## Package Structure

Five new packages following the existing monorepo layering convention:

```
nexus-symfony              — Bundle, runtime, request actor, coroutine DI, profiler,
                             scheduler, tracing, graceful shutdown, session enforcement
nexus-symfony-messenger    — Messenger→Actor routing bridge
nexus-symfony-doctrine     — Per-worker Swoole PDOPool + coroutine-scoped EM
nexus-symfony-testing      — MockActorRef, TestActorSystem, NexusTestTrait
nexus-symfony-worker-pool  — nexus:consume CLI command for Swoole worker pool
```

### Deptrac layers

```
SymfonyBundle          → Core, Runtime, App
SymfonyMessenger       → SymfonyBundle, Core
SymfonyDoctrine        → SymfonyBundle, Core
SymfonyTesting         → SymfonyBundle, Core, RuntimeStep
SymfonyWorkerPool      → SymfonyBundle, WorkerPool, WorkerPoolSwoole
```

---

## Package: `nexus-symfony`

### symfony/runtime bootstrap

```bash
APP_RUNTIME=Monadial\Nexus\Symfony\Runtime\NexusRuntime
APP_RUNTIME_OPTIONS='{"host":"0.0.0.0","port":8080,"workers":4}'
```

```php
// public/index.php — unchanged
require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return static function (array $context): HttpKernelInterface {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
```

`NexusRuntime` implements `Symfony\Component\Runtime\RuntimeInterface`:

```php
final class NexusRuntime implements RuntimeInterface
{
    public function __construct(private readonly array $options = []) {}

    public function getRunner(mixed $application): RunnerInterface
    {
        return new NexusRunner($application, $this->options);
    }
}
```

`NexusRunner::run()`:
1. Boots the Symfony kernel
2. Creates `ActorSystem` with `SwooleRuntime` (from bundle config)
3. Starts `Swoole\Http\Server` with `$options` (host, port, workers)
4. Each request: `SwooleHttpBridge` converts request → spawns `RequestActor` → `HttpKernelInterface::handle()` → converts response
5. Event loop runs until SIGTERM

**Config separation:**

| Location | Concerns |
|---|---|
| `APP_RUNTIME_OPTIONS` | Swoole server: host, port, SSL, worker thread count |
| `config/packages/nexus.php` | Actor system: pool sizes, shutdown timeout, doctrine, sessions |

---

### Bundle structure

```
nexus-symfony/src/
├── NexusBundle.php
├── DependencyInjection/
│   ├── NexusExtension.php
│   ├── Configuration.php
│   └── Compiler/
│       ├── ActorRegistrationPass.php    — #[AsActor], #[AsGlobalActor], #[AsActorHandler]
│       ├── ActorPropsFactoryPass.php    — registers ActorPropsFactory per actor
│       ├── CoroutineScopePass.php       — finds #[CoroutineScoped] services, generates proxies
│       ├── GlobalActorPass.php          — #[AsGlobalActor] hash ring or local fallback
│       └── DoctrinePass.php             — PDOPool or standard EM fallback
├── Runtime/
│   ├── NexusRuntime.php
│   └── NexusRunner.php
├── Http/
│   ├── SwooleHttpBridge.php             — Swoole\Http\Request ↔ HttpFoundation\Request
│   └── RequestActor.php                 — per-request Swoole coroutine actor
├── Coroutine/
│   ├── CoroutineContextInterface.php
│   ├── SwooleCoroutineContext.php       — production: Swoole\Coroutine::getContext()
│   ├── CoroutineScope.php               — stores per-coroutine service instances
│   └── CoroutineScopeListener.php       — priority 1000, main-request only
├── Actor/
│   ├── DelegatingActorHandler.php       — routes messages to #[AsActorHandler] methods
│   ├── ActorPropsFactory.php            — lazy Props construction after container compile
│   ├── ActorServiceLocator.php          — lazy service resolution inside actors
│   └── EnvelopeContext.php              — current envelope for Monolog tracing
├── Attribute/
│   ├── AsActor.php                      — long-lived, worker-local actor
│   ├── AsGlobalActor.php                — hash-routed across workers
│   ├── AsActorHandler.php               — method handles one message type
│   ├── WithActor.php                    — injection attribute for ActorRef
│   └── CoroutineScoped.php              — marks service as needing per-coroutine isolation
├── Tracing/
│   ├── NexusMonologProcessor.php        — reads from coroutine context OR envelope
│   ├── RequestIdListener.php            — reads X-Request-Id from inbound headers
│   └── ResponseIdListener.php          — writes X-Request-Id to outbound headers
├── Scheduler/
│   └── NexusSchedulerWorkerPass.php     — pins Symfony Scheduler to worker 0
├── Session/
│   └── SwooleSessionEnforcer.php        — boot-time check: rejects file sessions
└── DataCollector/
    └── NexusDataCollector.php           — profiler: actors, messages, dead letters
```

---

### Coroutine-local DI

The core isolation mechanism. Services marked `#[CoroutineScoped]` (or known Symfony services) get per-coroutine instances instead of shared singletons.

**`CoroutineScopePass`** (compile time):
1. Identifies coroutine-scoped services: `EntityManagerInterface`, `TokenStorageInterface`, `SessionInterface`, `RequestStack`, and any service tagged `#[CoroutineScoped]`
2. Generates a `CoroutineContextProxy` for each — delegates every method call to the instance stored in the current coroutine's context
3. Replaces the service definition in the container with the proxy
4. Registers factory callables in `CoroutineScope`

**`CoroutineScopeListener`** (priority 1000, `kernel.request`, main request only):
```php
public function onKernelRequest(RequestEvent $event): void
{
    if (!$event->isMainRequest()) {
        return;
    }

    $this->scope->initialize([
        EntityManagerInterface::class   => fn() => $this->emFactory->create(),
        TokenStorageInterface::class    => fn() => new TokenStorage(),
        SessionInterface::class         => fn() => new Session($this->sessionHandler),
        RequestStack::class             => fn() => new RequestStack(),
    ]);
}
```

**`CoroutineScope`** reads/writes `Swoole\Coroutine::getContext()` via `CoroutineContextInterface` — making it swappable for testing.

---

### Actor registration

**Long-lived, worker-local actor** — spawned at system start, runs for application lifetime:

```php
#[AsActor(name: 'order-saga')]
class OrderSagaActor implements ActorHandler
{
    private array $pendingOrders = [];

    public function handle(ActorContext $ctx, object $message): Behavior { ... }
}
```

**Global actor** — single logical actor, consistent-hash routed to one worker. Requires `nexus-worker-pool`. Falls back to `#[AsActor]` behaviour if worker pool absent:

```php
#[AsGlobalActor(name: 'payment-saga')]
class PaymentSagaActor implements ActorHandler { ... }
```

**Any service becomes an actor** — no interface required, no rewrite:

```php
#[AsActor(name: 'notifications')]
class NotificationService
{
    public function __construct(
        private readonly MailerInterface $mailer,
    ) {}

    #[AsActorHandler]
    public function sendWelcome(SendWelcomeEmail $msg): void
    {
        $this->mailer->send(...);
    }

    #[AsActorHandler]
    public function sendReset(SendPasswordReset $msg): void
    {
        $this->mailer->send(...);
    }
}
```

`ActorRegistrationPass` finds all `#[AsActor]`/`#[AsGlobalActor]` classes, generates a `DelegatingActorHandler` wrapper that routes by message type via reflection, registers each as an `ActorRef` service injectable anywhere.

**`ActorPropsFactory`** — lazy construction after container is compiled:

```php
final class ActorPropsFactory
{
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly string $actorClass,
    ) {}

    public function create(): Props
    {
        return Props::fromContainer($this->container, $this->actorClass);
    }
}
```

**Injection anywhere:**

```php
class OrderController extends AbstractController
{
    public function __construct(
        #[WithActor('order-saga')] private readonly ActorRef $orderSaga,
        private readonly ActorSystem $system,
    ) {}
}

class OrderRepository
{
    public function __construct(
        #[WithActor('order-saga')] private readonly ActorRef $orderSaga,
    ) {}
}
```

---

### SwooleHttpBridge

Converts between Swoole and Symfony HTTP objects:

```php
final class SwooleHttpBridge
{
    public function toSymfonyRequest(SwooleRequest $req): Request
    {
        return Request::create(
            uri:        $req->server['request_uri'],
            method:     $req->server['request_method'],
            parameters: $req->get ?? [],
            cookies:    $req->cookie ?? [],
            files:      $this->normaliseFiles($req->files ?? []),
            server:     $this->normaliseServer($req->server, $req->header),
            content:    $req->rawContent(),
        );
    }

    public function sendSymfonyResponse(Response $response, SwooleResponse $res): void
    {
        $res->status($response->getStatusCode());

        foreach ($response->headers->all() as $name => $values) {
            foreach ($values as $value) {
                $res->header($name, $value);
            }
        }

        if ($response instanceof StreamedResponse) {
            ob_start();
            $response->sendContent();
            $res->end(ob_get_clean());
            return;
        }

        $res->end($response->getContent());
    }
}
```

Covers: file uploads, cookies, streaming responses, chunked transfer encoding.

---

### Distributed tracing

Full pipeline — every log line from every actor carries tracing IDs:

**Inbound** (`RequestIdListener`, `kernel.request`, priority 900):
- Reads `X-Request-Id` / `traceparent` (W3C) from request headers
- Stores in coroutine context: `nexus.request_id`, `nexus.correlation_id`

**Propagation** (`NexusMonologProcessor`):
- Source 1: coroutine context (RequestActor — HTTP requests)
- Source 2: `EnvelopeContext::current()` (long-lived actors — reads from processing envelope)

```php
public function __invoke(LogRecord $record): LogRecord
{
    $context = Coroutine::getContext();

    if (isset($context['nexus.request_id'])) {
        return $record->with(extra: [
            ...$record->extra,
            'request_id'     => $context['nexus.request_id'],
            'correlation_id' => $context['nexus.correlation_id'],
        ]);
    }

    $envelope = $this->envelopeContext->current();

    if ($envelope !== null) {
        return $record->with(extra: [
            ...$record->extra,
            'request_id'     => $envelope->requestId,
            'correlation_id' => $envelope->correlationId,
            'causation_id'   => $envelope->causationId,
        ]);
    }

    return $record;
}
```

**Outbound** (`ResponseIdListener`, `kernel.response`):
- Writes `X-Request-Id` to response headers

---

### Symfony Scheduler integration

Auto-activated when `symfony/scheduler` is present. Scheduler pinned to worker 0 — fires exactly once across all workers:

```php
// NexusWorkerStartHandler
if ($node->workerId() === 0) {
    $this->scheduleRegistry->start();
}
```

Actors as scheduled tasks — combine `#[AsActorHandler]` with `#[AsPeriodicTask]`:

```php
#[AsActor(name: 'reports')]
class ReportGeneratorService
{
    #[AsActorHandler]
    #[AsPeriodicTask(frequency: '1 hour')]
    public function generate(GenerateReport $msg): void { ... }
}
```

Compiler pass detects the combination → registers schedule → routes trigger to `ActorRef->tell()`.

Actors can also self-schedule via `ActorContext::scheduleRepeatedly()` without Symfony Scheduler dependency.

---

### Graceful shutdown

```
SIGTERM received
  ↓ 1. Swoole HTTP server stops accepting connections
  ↓ 2. Wait for active RequestActor coroutines to finish (configurable timeout)
  ↓ 3. If timeout exceeded → log WARNING + force continue
  ↓ 4. Flush Messenger async transports (if nexus-symfony-messenger installed)
  ↓ 5. Drain + close per-worker PDO pools (if nexus-symfony-doctrine installed)
  ↓ 6. ActorSystem::shutdown()
  ↓ 7. Clean exit
```

```php
$nexus->shutdown(
    timeout: Duration::seconds(30),
    onTimeout: ShutdownTimeoutBehavior::ForceWithWarning,
);
```

---

### Session enforcement

File sessions are incompatible with persistent Swoole processes. Enforced at bundle boot:

```php
// NexusBundle::boot()
if ($this->isFileSessionHandler()) {
    throw new InvalidConfigurationException(
        'File sessions are not Swoole-compatible. Configure Redis or database session handler: $nexus->session(handler: SessionHandlerMode::Redis, dsn: "redis://localhost")'
    );
}
```

```php
$nexus->session(handler: SessionHandlerMode::Redis, dsn: 'redis://localhost:6379');
```

---

### Bundle configuration reference

```php
// config/packages/nexus.php
return static function (NexusConfig $nexus): void {
    $nexus
        ->name('my-app')
        ->shutdown(timeout: Duration::seconds(30), onTimeout: ShutdownTimeoutBehavior::ForceWithWarning)
        ->session(handler: SessionHandlerMode::Redis, dsn: 'redis://localhost:6379');
};
```

```bash
# .env
APP_RUNTIME=Monadial\Nexus\Symfony\Runtime\NexusRuntime
APP_RUNTIME_OPTIONS='{"host":"0.0.0.0","port":8080,"workers":4}'
```

---

## Package: `nexus-symfony-messenger`

### Messenger → Actor routing

Compiler pass detects when a `#[AsActor]`/`#[AsActorHandler]` service handles a message type also dispatched via the Messenger bus. Registers `ActorMessageHandler` bridge automatically — no manual mapping:

```php
$bus->dispatch(new ProcessOrder($orderId));
// → ActorRef->tell(new ProcessOrder($orderId))

// Request-response:
$envelope = $bus->dispatch(new ProcessOrder($orderId), [new ExpectReplyStamp()]);
// → ActorRef->ask(..., Duration::seconds(5)) → result in ReplyStamp
```

### Actor → Messenger

Works via normal DI — `MessageBusInterface` injects into actor services naturally:

```php
#[AsActor(name: 'orders')]
class OrderService
{
    public function __construct(private readonly MessageBusInterface $eventBus) {}

    #[AsActorHandler]
    public function processOrder(ProcessOrder $msg): void
    {
        $this->eventBus->dispatch(new OrderProcessed($msg->orderId));
    }
}
```

### Swoole guard

Boot-time enforcement — `ask()` requires async runtime:

```php
// NexusMessengerExtension::load()
if (!extension_loaded('swoole')) {
    throw new RuntimeException(
        'nexus-symfony-messenger requires ext-swoole. ask() cannot work without Swoole coroutine suspension.'
    );
}
```

`ask()` timeout configuration:

```php
// config/packages/messenger.php
$messenger->actorAskTimeout(Duration::seconds(5));
```

`ActorTransport` (Messenger pull-based transport backed by actor mailboxes) is explicitly **out of scope for V1** — pull/push impedance mismatch makes it fragile. Deferred to V2.

---

## Package: `nexus-symfony-doctrine`

### Per-worker Swoole PDOPool

Each Swoole worker thread owns its own coroutine-safe PDO pool. Connections are shared across coroutines within a worker — not across workers (Swoole thread memory isolation makes cross-worker sharing impossible):

```
Worker #1: SwooleCoroutinePDOPool (2 connections) → DB
Worker #2: SwooleCoroutinePDOPool (2 connections) → DB
Worker #3: SwooleCoroutinePDOPool (2 connections) → DB
Worker #4: SwooleCoroutinePDOPool (2 connections) → DB
```

A coroutine that needs a connection suspends automatically if the pool is exhausted — Swoole's coroutine scheduler resumes it when a connection is returned.

For large-scale deployments: use PgBouncer (PostgreSQL) or ProxySQL (MySQL) as external connection poolers — the right tool for connection counts far exceeding worker counts.

```php
// config/packages/nexus.php
$nexus->doctrine(connectionsPerWorker: 2);
```

### Coroutine-scoped EntityManager

`EntityManagerInterface` is registered as `#[CoroutineScoped]` — each request coroutine gets a fresh EM instance via `CoroutineScope`. Injecting `EntityManagerInterface` anywhere works exactly as in traditional Symfony:

```php
class OrderRepository
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public function save(Order $order): void
    {
        $this->em->persist($order);
        $this->em->flush();
    }
}
```

Transaction boundaries align naturally with the request actor lifetime. Standard Doctrine transaction patterns work unchanged.

### Long-lived actor Doctrine rule

Long-lived actors must NOT inject `EntityManagerInterface` directly — entities belong to request coroutine scope and become detached after the coroutine ends. Enforced by `DoctrineInActorRule` in `nexus-psalm`:

```
ERROR: EntityManagerInterface cannot be injected into #[AsActor] class OrderSagaActor.
       Inject it into request-scoped services instead.
```

### Test environment fallback

`DoctrineCompilerPass` detects absence of `ext-swoole` and falls back to standard single Doctrine connection — enabling standard transaction-per-test patterns:

```php
if (!extension_loaded('swoole')) {
    // Standard Doctrine connection — no pool
    // Transaction rollback per test works normally
    $container->setAlias(EntityManagerInterface::class, 'doctrine.orm.default_entity_manager');
    return;
}
// Register SwooleCoroutinePDOPool + coroutine proxy
```

---

## Package: `nexus-symfony-testing`

No `ext-swoole` required. Provides a `MockCoroutineContext` implementing `CoroutineContextInterface`:

```php
final class MockCoroutineContext implements CoroutineContextInterface
{
    private readonly ArrayObject $context;

    public function __construct()
    {
        $this->context = new ArrayObject();
    }

    public function current(): ArrayObject
    {
        return $this->context;
    }
}
```

Auto-swapped into the container in test environment. `NexusTestTrait` wraps each test in a fresh context:

```php
class OrderControllerTest extends WebTestCase
{
    use NexusTestTrait;

    public function testOrderCreation(): void
    {
        $mock = $this->mockActor('order-saga');
        $mock->expectTell(ProcessOrder::class);

        static::createClient()->request('POST', '/orders');

        $mock->assertToldOnce(ProcessOrder::class);
    }
}
```

Components:

| Class | Purpose |
|---|---|
| `MockCoroutineContext` | Simulates `Swoole\Coroutine::getContext()` without Swoole |
| `MockActorRef` | Records `tell()`/`ask()` calls, supports expectations |
| `TestActorSystem` | Wires `StepRuntime` for deterministic actor tests |
| `NexusTestTrait` | PHPUnit helpers, `mockActor()`, `assertToldOnce()`, context setup |

`config/packages/test/nexus.php` — no special configuration needed. Absence of `ext-swoole` auto-activates test mode.

---

## Package: `nexus-symfony-worker-pool`

Dedicated `nexus:consume` command — does not decorate or patch Symfony's internal `messenger:consume`:

```php
#[AsCommand(name: 'nexus:consume')]
final class NexusConsumeCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('transport', InputArgument::REQUIRED)
             ->addOption('workers', 'w', InputOption::VALUE_REQUIRED, default: '4');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        NexusSymfonyWorkerApp::run(
            config: WorkerPoolConfig::withThreads((int) $input->getOption('workers')),
            transport: $input->getArgument('transport'),
            kernel: $this->kernel,
        );

        return Command::SUCCESS;
    }
}
```

```bash
php bin/console nexus:consume orders --workers=4
```

Each worker thread:
1. Boots its own Symfony kernel
2. Gets its own DI container, EM, sessions
3. Runs its own Messenger consumer loop
4. Each consumed message → short-lived handler actor → process → stop

Graceful shutdown: SIGTERM → finish current message per worker → drain → exit. Identical to HTTP mode.

---

## Resolved weak points

| Issue | Resolution |
|---|---|
| Dev/prod difference | Swoole-only — no Fiber mode |
| ServicesResetter gaps | Coroutine-local DI via `CoroutineScope` + generated proxies |
| Security token scope | `TokenStorageInterface` is `#[CoroutineScoped]` — fresh per coroutine |
| Sessions | `SwooleSessionEnforcer` boot check + `SwooleSessionHandler` |
| Named actor routing | Two-tier: `#[AsActor]` worker-local, `#[AsGlobalActor]` hash-routed |
| Doctrine lazy loading | `DoctrineInActorRule` Psalm enforcement |
| Props compiler pass | `ActorPropsFactory` lazy pattern |
| Distributed tracing | `NexusMonologProcessor` reads coroutine context OR envelope |
| Messenger `ask()` | Boot-time Swoole guard + configurable timeout |
| Graceful shutdown | Complete sequence with configurable timeout enforcement |
| Multiple ActorSystems | Explicit V1 scope — single system, compile-time error if attempted |
| PDO pool cross-thread | Per-worker pool only (thread memory isolation) |
| RequestActor overhead | RequestActor IS the Swoole coroutine — zero extra overhead |
| ActorTransport | Dropped from V1 (pull/push impedance mismatch) |
| `#[AsActor]` semantics | Long-lived actors only — not wrappers for every service |
| `CoroutineScopeListener` priority | `priority: 1000` — fires before security and all other listeners |
| Sub-request scope reset | `isMainRequest()` guard — sub-requests inherit parent coroutine scope |
| Monolog in long-lived actors | `EnvelopeContext` fallback — reads from processing envelope |
| PDO pool in testing | Auto-disabled without `ext-swoole`, standard Doctrine fallback |
| `#[AsGlobalActor]` single-server | Degrades to `#[AsActor]` when worker pool absent |
| Messenger:consume decoration | Dedicated `nexus:consume` command |
| Swoole HTTP bridge | `SwooleHttpBridge` — files, streams, cookies, chunked transfer |
| Scheduler fires N times | Pinned to worker 0, dispatches via hash ring |
| Testing without Swoole | `CoroutineContextInterface` + `MockCoroutineContext` |
| Server configuration | `APP_RUNTIME_OPTIONS` — separate from bundle config |

---

## Out of scope (V2)

- WebSocket / SSE / per-connection actors
- Multiple `ActorSystem` instances per app
- `ActorTransport` (Messenger pull transport backed by actors)
- Fiber runtime support
- Remote cluster (multi-node) HTTP routing
