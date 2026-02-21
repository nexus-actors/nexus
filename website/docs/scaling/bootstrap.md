---
sidebar_position: 3
title: Running Multi-Process
---

# Running Multi-Process

## ThreadClusterBootstrap

`ThreadClusterBootstrap` is the entry point for multi-thread scaling. It creates
Swoole threads, sets up transport and directory infrastructure, and starts each
worker with a `ClusterNode`.

```php
use Monadial\Nexus\Cluster\ClusterConfig;
use Monadial\Nexus\Cluster\ClusterNode;
use Monadial\Nexus\Cluster\SwooleThread\ThreadClusterBootstrap;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;

ThreadClusterBootstrap::create(ClusterConfig::withWorkers(8))
    ->onWorkerStart(function (ClusterNode $node): void {
        // Spawn actors -- hash ring determines local vs remote
        $node->spawn(Props::fromBehavior($orderBehavior), 'orders');
        $node->spawn(Props::fromBehavior($paymentBehavior), 'payments');
        $node->spawn(Props::fromBehavior($inventoryBehavior), 'inventory');
    })
    ->run();
```

### What happens on `run()`

1. A `Swoole\Thread\Map` is created for the shared actor directory.
2. A `Swoole\Thread\Queue` is created for each worker.
3. Worker threads are spawned.
4. Each worker thread starts independently and:
   - Creates a `SwooleRuntime` and `ActorSystem`.
   - Creates a `ThreadMapDirectory` backed by the shared map.
   - Creates a `ThreadQueueTransport` with the worker's queue.
   - Creates a `ClusterNode` and starts its transport listener.
   - Calls your `onWorkerStart` callback with the node.
   - Runs the actor system event loop.

### Spawning actors

Every worker calls `spawn()` for every actor. The `ConsistentHashRing`
determines which worker actually creates each actor:

```php
->onWorkerStart(function (ClusterNode $node): void {
    // On worker 3, this might return:
    // - LocalActorRef for 'orders' (if hash ring assigns it to worker 3)
    // - RemoteActorRef for 'payments' (if assigned to worker 1)
    $ordersRef = $node->spawn(Props::fromBehavior($orderBehavior), 'orders');
    $paymentsRef = $node->spawn(Props::fromBehavior($paymentBehavior), 'payments');

    // Both refs implement ActorRef<T> -- usage is identical
    $ordersRef->tell(new ProcessOrder($orderId));
    $paymentsRef->tell(new ChargePayment($amount));
})
```

### Looking up actors

Use `ClusterNode::actorFor()` to resolve an actor by path:

```php
$ref = $node->actorFor('/user/orders');

if ($ref !== null) {
    $ref->tell(new ProcessOrder($orderId));
}
```

Returns `LocalActorRef` if the actor is on the current worker,
`RemoteActorRef` if on another worker, or `null` if the actor is not
registered in the directory.

### Custom serializer

Replace the default PHP native serializer:

```php
ThreadClusterBootstrap::create($config)
    ->withSerializer(new MyCustomSerializer())
    ->onWorkerStart(function (ClusterNode $node): void {
        // ...
    })
    ->run();
```

## Running a script

Multi-process scaling requires the Swoole PHP extension. Save your script as a
regular PHP file and run it directly:

```bash
# If you have Swoole installed locally
php cluster.php

# Or via Docker (Swoole provided by the container)
docker compose exec php-swoole php cluster.php
```

The `run()` call blocks -- the script stays alive until the process pool is
stopped (e.g., via `SIGTERM` or `Ctrl+C`).

### With Docker Compose

If using the provided Docker setup:

```bash
# Start containers
make up

# Run your scaling script
docker compose exec php-swoole php your-script.php

# Run integration tests
make test-thread-cluster
```

## Example: distributed counter

A complete example with actors distributed across workers:

```php
use Monadial\Nexus\Cluster\ClusterConfig;
use Monadial\Nexus\Cluster\ClusterNode;
use Monadial\Nexus\Cluster\SwooleThread\ThreadClusterBootstrap;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\BehaviorWithState;
use Monadial\Nexus\Core\Actor\Props;

// Define a stateful counter actor
$counterBehavior = Behavior::withState(
    0,
    function (ActorContext $ctx, object $msg, int $count): BehaviorWithState {
        $next = $count + 1;
        $ctx->log()->info("Counter at {$next} on worker {$msg->workerId}");
        return BehaviorWithState::next($next);
    },
);

// Run with 4 workers
ThreadClusterBootstrap::create(ClusterConfig::withWorkers(4))
    ->onWorkerStart(function (ClusterNode $node) use ($counterBehavior): void {
        // Each worker spawns all actors, but only the owner creates them locally
        for ($i = 0; $i < 100; $i++) {
            $ref = $node->spawn(
                Props::fromBehavior($counterBehavior),
                "counter-{$i}",
            );

            // Send a message to each -- local or remote, it just works
            $ref->tell((object) ['workerId' => $node->workerId()]);
        }
    })
    ->run();
```

With 4 workers, roughly 25 of the 100 counters will be local to each worker.
Messages to remote counters are transparently routed via thread queues.
