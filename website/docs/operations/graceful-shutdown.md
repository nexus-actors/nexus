---
title: Graceful Shutdown
related:
  - operations/runbook
  - operations/deployment/kubernetes
  - core-concepts/dead-letters
---

# Graceful Shutdown

Nexus provides deadline-driven graceful shutdown: call `shutdown(Duration $timeout)` and the system sends `PoisonPill` to every top-level actor, waits cooperatively for them to drain, then force-stops any survivors before handing off to the runtime.

## How shutdown works

`ActorSystem::shutdown(Duration $timeout)` follows this sequence:

1. **Mark stopping** — `$this->stopping = true`. Repeated calls are idempotent.
2. **Broadcast `PoisonPill`** — every top-level actor under `/user` receives a `PoisonPill`. Each actor processes messages already in its mailbox, delivers `PostStop` to itself and its children, then stops.
3. **Cooperative yield loop** — the system calls `runtime->yield()` in a tight loop until all children have stopped or the deadline (`hrtime(true) + timeout->toNanos()`) expires.
4. **Force-stop survivors** — any actor still alive after the deadline has `initiateStop()` called directly, which closes its mailbox (unblocking any `dequeueBlocking` fiber) and delivers `PostStop`.
5. **Runtime shutdown** — `runtime->shutdown($timeout)` tears down the event loop.

```php title="src/Bootstrap/App.php"
$system = ActorSystem::create('my-app', new FiberRuntime());
$ref    = $system->spawn(Props::fromBehavior($workerBehavior), 'worker');

// Schedule shutdown after 30 s; actors get 5 s to drain.
$system->runtime()->scheduleOnce(
    Duration::seconds(30),
    fn() => $system->shutdown(Duration::seconds(5)),
);

$system->run(); // blocks until runtime stops
```

## Drain order

Actors form a supervision tree. `PoisonPill` propagates depth-first: a parent's `PoisonPill` handler stops its children first, delivers their `PostStop` signals, then stops itself. This means leaves drain before their parents — the order you want for releasing resources like database connections.

To hook cleanup:

```php title="src/Actors/DatabaseActor.php"
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Lifecycle\PostStop;

$behavior = Behavior::receive(
    static fn($ctx, $msg) => Behavior::same(),
)->onSignal(static function ($ctx, $signal) use ($connection): Behavior {
    if ($signal instanceof PostStop) {
        $connection->close();
    }

    return Behavior::same();
});
```

## Swoole thread mode: `BeforeShutdown` watchdog

In `SwooleThreadServer`, Swoole fires a `BeforeShutdown` event in the main thread when `SIGTERM` or `SIGINT` arrives. Nexus uses a shared `Atomic` flag to bridge from the main thread into each worker thread:

1. `BeforeShutdown` sets the shared atomic to `1`.
2. Each worker thread runs a watchdog coroutine that polls the atomic.
3. When the atomic flips, the watchdog calls `$system->shutdown($shutdownTimeout)` inside its coroutine context.

This is handled automatically by `SwooleThreadServer`. No user code is needed unless you have cleanup outside the actor system.

```php title="src/Server/Boot.php"
use Monadial\Nexus\Http\Server\Swoole\Threads\Server\SwooleThreadConfig;
use Monadial\Nexus\Http\Server\Swoole\Threads\Server\SwooleThreadServer;

$config = SwooleThreadConfig::default()
    ->withShutdownTimeout(Duration::seconds(10));

SwooleThreadServer::run($app, $config);
```

## Kubernetes `terminationGracePeriodSeconds`

When Kubernetes sends `SIGTERM`, your pod has `terminationGracePeriodSeconds` seconds before it receives `SIGKILL`. Set this to slightly more than your actor shutdown timeout to give Nexus time to drain:

```yaml title="k8s/deployment.yaml"
spec:
  template:
    spec:
      terminationGracePeriodSeconds: 30   # must exceed shutdownTimeout
      containers:
        - name: app
          lifecycle:
            preStop:
              exec:
                # Give load balancer time to stop routing traffic before SIGTERM.
                command: ["/bin/sleep", "5"]
```

With the `preStop` hook adding 5 seconds before `SIGTERM` fires, a `shutdownTimeout` of `Duration::seconds(20)` fits safely inside a 30-second `terminationGracePeriodSeconds`.

## Choosing the timeout

| Use case | Recommended timeout |
|---|---|
| Stateless HTTP workers | `Duration::seconds(5)` |
| Actors with database writes | `Duration::seconds(15)` |
| Event-sourced actors mid-snapshot | `Duration::seconds(30)` |
| Worker pool with cross-thread draining | `Duration::seconds(30)` |

Set the timeout conservatively. An actor that takes longer than the deadline does not lose messages — its mailbox is force-closed and `PostStop` still fires — but any messages remaining in the mailbox at that point are dropped to dead letters.

## Caveats

- `shutdown()` is idempotent but not thread-safe. Call it from within a running coroutine or fiber.
- `PoisonPill` is processed in order with other messages. If an actor has a large backlog, it processes the entire backlog before honouring the pill. Size timeouts accordingly.
- Actors that block inside a handler (sleep, synchronous I/O) will not respond to `PoisonPill` until the blocking call returns. Avoid blocking calls in handlers; see [best practices](../best-practices/overview.md).

## See also

- [On-call runbook](./runbook.md)
- [Kubernetes deployment](./deployment/kubernetes.md)
- [Dead letters](../core-concepts/dead-letters.md)
- [Performance tuning](./performance-tuning.md)
