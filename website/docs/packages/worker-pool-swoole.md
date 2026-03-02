# nexus-worker-pool-swoole

Swoole thread primitives for the worker pool: `Thread\Queue` transport,
`Thread\Map` directory, and `Thread\Pool` bootstrap.

## Prerequisites

- **ZTS PHP 8.5+** — verify with `php -r "echo PHP_ZTS;"` (must print `1`)
- **Swoole 6.0+** compiled with `--enable-swoole-thread` — verify with
  `php -r "var_dump(Swoole\Thread::isAvailable());"`

## Installation

```bash
composer require nexus-actors/worker-pool-swoole
```

## WorkerPool DSL

`WorkerPool` is the recommended entry point. It provides a fluent builder that
configures actors, closure-based behaviors, and lifecycle hooks, then bootstraps
the thread pool.

```php
use Monadial\Nexus\WorkerPool\Swoole\WorkerPool;
use Monadial\Nexus\WorkerPool\Swoole\WorkerPoolHandle;
use Monadial\Nexus\WorkerPool\WorkerNode;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Runtime\Mailbox\MailboxConfig;

WorkerPool::withThreads(8)
    // Optional: name each worker system "{prefix}-{workerId}"
    ->withName('shop')

    // Register a class-based actor (fresh instance per worker)
    ->actor('orders', OrderActor::class)

    // Register a class-based actor with a bounded mailbox
    ->actor('payments', PaymentActor::class, MailboxConfig::bounded(5_000))

    // Register a stateful class-based actor
    ->stateful('counter', CounterActor::class)

    // Register a closure-based actor (closure must be static)
    ->behavior('greeter', static fn (): Behavior => Behavior::receive(
        static fn ($ctx, $msg): Behavior => Behavior::same(),
    ))

    // Run arbitrary code once per worker after ActorSystem starts
    ->configure(static function (WorkerNode $node): void {
        $node->log()->info('Worker ready', ['id' => $node->workerId()]);
    })

    // Run a callback in the main thread after all workers are ready
    ->onStart(function (WorkerPoolHandle $handle): void {
        // inject a message, monitor the pool, then let it run
    })

    ->run();
```

### Entry-point constructors

| Method | Description |
|--------|-------------|
| `WorkerPool::withThreads(int $count): self` | Create a pool with an explicit thread count. |
| `WorkerPool::withCpuThreads(): self` | Create a pool with one thread per CPU core (`swoole_cpu_num()`). |

### Builder methods

| Method | Description |
|--------|-------------|
| `withName(string $name): self` | Set the actor system name prefix. Each worker is named `"{prefix}-{id}"`. Default: `"worker"`. |
| `withLogger(string $loggerClass): self` | Inject a PSR-3 logger by class name. Each thread calls `new $loggerClass()`. |
| `withLoggerFactory(Closure $factory): self` | Inject a PSR-3 logger via factory closure. The closure is serialized via opis/closure and called per thread. Must be static. |
| `actor(string $name, string $class, ?MailboxConfig $mailbox = null): self` | Register a class-based `ActorHandler`. A fresh instance is created per worker. |
| `stateful(string $name, string $class, ?MailboxConfig $mailbox = null): self` | Register a class-based `StatefulActorHandler`. A fresh instance is created per worker. |
| `behavior(string $name, Closure $factory, ?MailboxConfig $mailbox = null): self` | Register a closure-based actor. The factory returns a `Behavior` and must be static with no object captures. |
| `configure(Closure $setup): self` | Receive the `WorkerNode` directly per worker. Use for custom spawning or dependency wiring. The closure must be static. |
| `onStart(Closure $callback): self` | Run a callback in the **main thread** after all workers are ready. Receives a `WorkerPoolHandle`. |
| `run(): void` | Bootstrap and start the pool. Blocks until all workers exit. |

### Thread safety rules

- Closures passed to `behavior()`, `configure()`, and `withLoggerFactory()` are serialized
  with [opis/closure](https://github.com/opis/closure) before being sent to worker threads.
  They **must be static** and capture only serializable values (no object instances, no
  resource handles).
- Class-string actors (`actor()`, `stateful()`) are always thread-safe. Only the string
  class name crosses thread boundaries; each worker calls `new $class()` locally.
- The `onStart()` closure runs in the main thread and is never serialized. It can capture
  arbitrary values and does not need to be static.

## WorkerPoolApp (class-based alternative)

For complex setups or when dependency injection is required, extend `WorkerPoolApp`
and override `configure()`. The class is passed as a string to the bootstrap and
re-instantiated fresh in each worker thread.

```php
use Monadial\Nexus\WorkerPool\Swoole\WorkerPoolApp;
use Monadial\Nexus\WorkerPool\WorkerNode;
use Monadial\Nexus\WorkerPool\WorkerPoolConfig;
use Monadial\Nexus\Core\Actor\Props;

final class MyApp extends WorkerPoolApp
{
    protected function configure(WorkerNode $node): void
    {
        $node->spawn(Props::fromBehavior($ordersBehavior), 'orders');
        $node->spawn(Props::fromFactory(fn () => new PaymentActor()), 'payments');
    }
}

MyApp::run(WorkerPoolConfig::withThreads(swoole_cpu_num()));
```

`configure()` runs once per thread. Closures constructed inside `configure()` are safe
because the whole class is re-instantiated locally in each thread — nothing crosses a
thread boundary.

**Comparison with the DSL:**

| | `WorkerPool` DSL | `WorkerPoolApp` subclass |
|---|---|---|
| Entry point | `WorkerPool::withThreads(n)->...->run()` | `MyApp::run(WorkerPoolConfig::withThreads(n))` |
| Actor registration | fluent builder methods | imperative `configure()` method |
| Closure serialization | opis/closure (must be static) | none — closures built per thread |
| Main-thread callback | `->onStart(fn(WorkerPoolHandle): void)` | not supported directly |

Use `WorkerPoolApp` when actor setup involves closures that cannot easily be made static
(e.g., closures that capture constructor-injected objects).

## WorkerPoolBootstrap

`WorkerPoolBootstrap` is the lower-level bootstrap. It creates N worker threads via
`Swoole\Thread\Pool`, shares a `Thread\Map` directory and one `Thread\Queue` inbox per
worker, then waits for all workers to report ready.

```php
use Monadial\Nexus\WorkerPool\Swoole\WorkerPoolBootstrap;
use Monadial\Nexus\WorkerPool\WorkerPoolConfig;

WorkerPoolBootstrap::create(WorkerPoolConfig::withThreads(4))
    ->withHandler(MyWorkerStartHandler::class)
    ->run();
```

`withHandler()` accepts a `class-string<WorkerStartHandler>`. The class is instantiated
fresh per thread. `run()` blocks until the pool exits.

Both the `WorkerPool` DSL and `WorkerPoolApp` wrap `WorkerPoolBootstrap` internally. Use
`WorkerPoolBootstrap` directly only when the higher-level APIs do not provide enough
flexibility.

## WorkerPoolHandle

`WorkerPoolHandle` is the main-thread handle passed to the `onStart()` callback. It
provides access to the shared thread structures created during bootstrap.

```php
WorkerPool::withThreads(4)
    ->actor('processor', ProcessorActor::class)
    ->onStart(function (WorkerPoolHandle $handle): void {
        echo 'Workers ready: ' . $handle->workerCount() . PHP_EOL;
        // $handle->stop() shuts the pool down gracefully
    })
    ->run();
```

| Method | Description |
|--------|-------------|
| `workerCount(): int` | Number of worker threads in the pool. |
| `queues(): array` | The `Swoole\Thread\Queue` inbox for each worker (indexed by worker ID). |
| `directory(): Map` | The shared `Swoole\Thread\Map` actor directory. |
| `stop(): void` | Signal all workers to shut down. |

## ThreadQueueTransport

Thread-safe `WorkerTransport` backed by one `Swoole\Thread\Queue` per worker.

```php
use Swoole\Thread\Queue;
use Monadial\Nexus\WorkerPool\Swoole\ThreadQueueTransport;

$queues    = [0 => new Queue(), 1 => new Queue(), 2 => new Queue()];
$transport = new ThreadQueueTransport($queues, workerId: 1);

$transport->send(0, $envelope);  // push to worker 0's inbox
$transport->listen($handler);    // start adaptive-poll receive coroutine
$transport->close();             // stop the receive loop
```

### Adaptive poll backoff

The receive coroutine uses non-blocking `Queue::pop(0)` to stay coroutine-friendly.
Blocking `pop()` would freeze the OS thread and prevent other coroutines from running.

| Consecutive empty polls | Sleep |
|------------------------|-------|
| 0 (message present) | none — tight drain loop |
| 1 – 99 | 1 ms |
| 100 – 999 | 5 ms |
| ≥ 1000 | 10 ms (idle steady state) |

When a message arrives, the counter resets to zero and the tight drain loop resumes.
No application-level serializer is needed — `Thread\Queue` handles the internal memory
copy automatically.

## ThreadMapDirectory

Thread-safe `WorkerDirectory` backed by a shared `Swoole\Thread\Map`.

```php
use Swoole\Thread\Map;
use Monadial\Nexus\WorkerPool\Swoole\ThreadMapDirectory;

$map = new Map();  // shared across all workers
$dir = new ThreadMapDirectory($map);

$dir->register('/user/orders', 2);
$dir->lookup('/user/orders');  // 2
$dir->has('/user/orders');     // true
```

All worker threads share the same `Map` instance, which is created in the main thread
and passed to each worker via `Thread\Pool` arguments. `Thread\Map` handles
synchronization internally — no additional locking is needed.

## WorkerRunnable

Thread entrypoint implementing `Swoole\Thread\Runnable`. Used internally by
`WorkerPoolBootstrap`. Direct instantiation is not necessary.

On `run()`:

1. Atomically claims a worker ID via `Thread\Atomic`.
2. Creates `SwooleRuntime`, `ActorSystem`, `ThreadMapDirectory`, `ThreadQueueTransport`.
3. Builds `ConsistentHashRing` and `WorkerNode`.
4. Calls `$node->start()` (registers transport listener).
5. Instantiates and calls the `WorkerStartHandler`.
6. Calls `$system->run()` — blocks until shutdown.
