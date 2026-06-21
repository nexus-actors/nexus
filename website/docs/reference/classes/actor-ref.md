---
title: ActorRef
sidebar_position: 5
related:
  - core-concepts/actors
  - core-concepts/ask-pattern
  - reference/classes/actor-system
  - reference/classes/props
---

# ActorRef

Type-safe reference to a running actor — the only handle user code holds.

## What it does

`ActorRef<T>` is the public face of every actor. You obtain a ref from
`ActorSystem::spawn()` or `ActorContext::spawn()` and use it to send messages without
any knowledge of where or how the actor is running — local fiber, Swoole coroutine,
remote worker thread, or dead-letter sink. All communication goes through two methods:
`tell()` for fire-and-forget delivery (never blocks, never throws), and `ask()` for
request-response (suspends the current fiber/coroutine until the reply arrives or the
timeout elapses). Because `ActorRef` is an interface, user code stays decoupled from
the concrete implementation (`LocalActorRef`, `WorkerActorRef`, or `DeadLetterRef`).

## Example

```php title="src/OrderService.php"
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Runtime\Duration;

/** @var ActorRef<OrderCommand> $orderActor */
$orderActor = $system->spawn(Props::fromFactory(fn() => new OrderActor()), 'orders');

// Fire-and-forget — enqueues immediately, never blocks
$orderActor->tell(new PlaceOrder($orderId, $items));

// Request-response — suspends until the actor replies or timeout elapses
$status = $orderActor->ask(new GetStatus($orderId), Duration::seconds(5))->await();

// Path and liveness checks
echo $orderActor->path();      // /user/orders
echo $orderActor->isAlive() ? 'running' : 'stopped';
```

## Key methods

- `tell(T $message): void` — fire-and-forget; enqueues the message in the actor's mailbox and returns immediately.
- `ask(object $message, Duration $timeout): Future<R>` — sends `$message` and returns a `Future` for the reply; call `->await()` to block until the reply arrives.
- `path(): ActorPath` — the hierarchical path of this actor (e.g. `/user/orders`).
- `isAlive(): bool` — `false` once the actor has stopped or the underlying mailbox is closed.

## Full API reference

[Full method list and class hierarchy](https://api.nexusactors.com/classes/Monadial-Nexus-Core-Actor-ActorRef.html)

## See also

- [Ask pattern](../../core-concepts/ask-pattern) — how request-response works and when to use it
- [Actors concept](../../core-concepts/actors) — the actor model and location transparency
- [ActorSystem](actor-system) — `spawn()` returns an `ActorRef`
- [Props](props) — configures how the actor behind the `ActorRef` is created
