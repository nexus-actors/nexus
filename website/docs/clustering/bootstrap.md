---
sidebar_position: 3
title: Running a Cluster
---

# Running a Cluster

## ClusterBootstrap

`ClusterBootstrap` is the entry point for starting a multi-process cluster.
It creates a `Swoole\Process\Pool`, sets up transport and directory
infrastructure, and starts each worker with a `ClusterNode`.

```php
use Monadial\Nexus\Cluster\ClusterConfig;
use Monadial\Nexus\Cluster\ClusterNode;
use Monadial\Nexus\Cluster\Swoole\ClusterBootstrap;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;

ClusterBootstrap::create(ClusterConfig::withWorkers(8))
    ->onWorkerStart(function (ClusterNode $node): void {
        // Spawn actors -- hash ring determines local vs remote
        $node->spawn(Props::fromBehavior($orderBehavior), 'orders');
        $node->spawn(Props::fromBehavior($paymentBehavior), 'payments');
        $node->spawn(Props::fromBehavior($inventoryBehavior), 'inventory');
    })
    ->run();
```

### What happens on `run()`

1. A `Swoole\Table` is created for the shared actor directory.
2. The socket directory is created if it doesn't exist.
3. A `Swoole\Process\Pool` is created with `workerCount` workers.
4. Each worker process starts independently and:
   - Creates a `SwooleRuntime` and `ActorSystem`.
   - Creates a `SwooleTableDirectory` backed by the shared table.
   - Creates a `UnixSocketTransport` and binds its server socket.
   - Waits briefly for all workers to bind, then connects to peers.
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
ClusterBootstrap::create($config)
    ->withSerializer(new MyCustomSerializer())
    ->onWorkerStart(function (ClusterNode $node): void {
        // ...
    })
    ->run();
```

## Running with Docker

The cluster requires the Swoole extension. Use the provided Docker setup:

```bash
# Start containers
make up

# Run cluster (from a PHP script using ClusterBootstrap)
docker compose exec php-swoole php your-cluster-script.php

# Run cluster integration tests
make test-cluster
```

## Example: distributed counter

A complete example with actors distributed across workers:

```php
use Monadial\Nexus\Cluster\ClusterConfig;
use Monadial\Nexus\Cluster\ClusterNode;
use Monadial\Nexus\Cluster\Swoole\ClusterBootstrap;
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
ClusterBootstrap::create(ClusterConfig::withWorkers(4))
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
Messages to remote counters are transparently routed via Unix sockets.
