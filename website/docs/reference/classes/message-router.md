---
title: MessageRouter
sidebar_position: 28
related:
  - packages/messenger
  - packages/cluster
---

# MessageRouter

Pluggable inbound routing interface used by `ReceiverActor` to resolve each incoming Messenger envelope to the Nexus `ActorRef` that should receive its message.

## What it does

`MessageRouter` is a single-method interface. `ReceiverActor` calls `route()` for every envelope it drains from the transport. Returning `null` marks the message unroutable and triggers the `UnroutablePolicy` configured on `ReceiverActorConfig` (reject or dead-letters).

Two concrete implementations ship with the package:

### MapMessageRouter

Exact PHP message class → `ActorRef` lookup. This is the right choice for most applications where message types are known at startup.

```php
/**
 * @param array<class-string, ActorRef<object>> $routes
 */
public function __construct(private array $routes)
```

`route()` returns `$routes[$message::class]` or `null` if the class is not in the map. The lookup is an O(1) array fetch.

### StampMessageRouter

Cluster seam: resolves the `TargetActorPathStamp` on the envelope against a path-keyed registry. Use this when a remote producer stamps the target actor path (e.g., when routing across cluster nodes). Messages without the stamp, or with a path not in the registry, are unroutable.

```php
/**
 * @param array<string, ActorRef<object>> $registry keyed by actor-path string
 */
public function __construct(private array $registry)
```

`route()` reads `$envelope->last(TargetActorPathStamp::class)` and looks up `$registry[$stamp->path]`.

## Interface

```php
interface MessageRouter
{
    /**
     * @return ActorRef<object>|null null means unroutable
     */
    public function route(object $message, Envelope $envelope): ?ActorRef;
}
```

## Example

```php title="src/bootstrap.php"
use Monadial\Nexus\Messenger\Routing\MapMessageRouter;
use Monadial\Nexus\Messenger\Routing\StampMessageRouter;

// Type-based routing (most common)
$router = new MapMessageRouter([
    OrderPlaced::class  => $ordersActor,
    PaymentMade::class  => $paymentsActor,
]);

// Path-stamp routing (cluster seam)
$router = new StampMessageRouter([
    '/user/orders'   => $ordersActor,
    '/user/payments' => $paymentsActor,
]);

// Custom router: route on any envelope property
$router = new class ($priorityRef, $defaultRef) implements MessageRouter {
    public function __construct(
        private readonly ActorRef $priorityRef,
        private readonly ActorRef $defaultRef,
    ) {}

    public function route(object $message, Envelope $envelope): ?ActorRef {
        // inspect stamps, message fields, etc.
        return $message instanceof PriorityMessage
            ? $this->priorityRef
            : $this->defaultRef;
    }
};
```

## Full API reference

[MessageRouter interface](https://api.nexusactors.com/classes/Monadial-Nexus-Messenger-Routing-MessageRouter.html) ·
[MapMessageRouter](https://api.nexusactors.com/classes/Monadial-Nexus-Messenger-Routing-MapMessageRouter.html) ·
[StampMessageRouter](https://api.nexusactors.com/classes/Monadial-Nexus-Messenger-Routing-StampMessageRouter.html)

## See also

- [nexus-messenger package](../../packages/messenger) — bridge overview and full wiring guide
- [Scaling & Clustering](../../packages/cluster) — cluster package that uses `StampMessageRouter` as a seam
- [ReceiverActor](receiver-actor) — the consumer that calls `route()` on every envelope
- [Messenger bridge guide](../../guides/messenger-bridge) — end-to-end routing examples
