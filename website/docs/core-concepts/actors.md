---
sidebar_position: 1
title: Actors
---

# Actors

Actors are the fundamental unit of computation in Nexus. Each actor encapsulates state, processes messages sequentially from its mailbox, and communicates with other actors exclusively through asynchronous message passing. This page covers the core types that make up the actor model: references, contexts, the actor system, paths, class-based actors, and dead letters.

## ActorRef

`ActorRef<T>` is the interface through which actor code communicates with other actors. Internal state is never accessed directly — all interaction happens through messages sent via the reference.

```php
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Mailbox\Envelope;
use Monadial\Nexus\Runtime\Async\Future;
use Monadial\Nexus\Runtime\Duration;

/** @template T of object */
interface ActorRef
{
    /** @param T $message */
    public function tell(object $message): void;

    /**
     * Deliver a pre-formed envelope directly.
     * Used by ActorContext::tell() to propagate correlation and causation IDs.
     */
    public function enqueueEnvelope(Envelope $envelope): void;

    /**
     * @template R of object
     * @param T $message
     * @return Future<R>
     * @throws AskTimeoutException
     */
    public function ask(object $message, Duration $timeout): Future;

    public function path(): ActorPath;

    public function isAlive(): bool;
}
```

### tell -- fire-and-forget messaging

`tell()` sends a message to the actor without waiting for a response. The message is enqueued in the actor's mailbox and processed asynchronously.

```php
readonly class Greet
{
    public function __construct(public string $name) {}
}

$greeter->tell(new Greet('Alice'));
```

### ask -- request-response

`ask()` sends a message and waits for a reply within a timeout. The call returns a `Future<R>` — awaiting it blocks the current fiber until a reply arrives or the timeout expires.

```php
readonly class GetCount
{
    public function __construct(public ActorRef $replyTo) {}
}

readonly class CountResult
{
    public function __construct(public int $count) {}
}

/** @var CountResult $result */
$result = $counter->ask(new GetCount($replyTo), Duration::seconds(5))->await();

echo $result->count; // 42
```

If the actor does not respond within the timeout, an `AskTimeoutException` is thrown. See the [Ask Pattern](./ask-pattern.md) page for the full request-reply protocol.

### path and isAlive

```php
echo $ref->path();     // "/user/orders/order-123"
echo $ref->isAlive();  // true
```

## ActorContext

`ActorContext<T>` is available inside message handlers and provides the actor's view of the world: its own reference, its parent, the ability to spawn children, manage watches, schedule messages, and more.

```php
use Closure;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Exception\NoSenderException;
use Fp\Functional\Option\Option;

/** @template T of object */
interface ActorContext
{
    /** @return ActorRef<T> */
    public function self(): ActorRef;

    /** @return Option<ActorRef<object>> */
    public function parent(): Option;

    public function path(): ActorPath;

    /**
     * @template C of object
     * @param Props<C> $props
     * @return ActorRef<C>
     */
    public function spawn(Props $props, string $name): ActorRef;

    /** @param ActorRef<object> $child */
    public function stop(ActorRef $child): void;

    /** @return Option<ActorRef<object>> */
    public function child(string $name): Option;

    /** @return array<string, ActorRef<object>> */
    public function children(): array;

    /** @param ActorRef<object> $target */
    public function watch(ActorRef $target): void;

    /** @param ActorRef<object> $target */
    public function unwatch(ActorRef $target): void;

    /** @param T $message */
    public function scheduleOnce(Duration $delay, object $message): Cancellable;

    /** @param T $message */
    public function scheduleRepeatedly(
        Duration $initialDelay,
        Duration $interval,
        object $message,
    ): Cancellable;

    public function stash(): void;

    public function unstashAll(): void;

    public function log(): LoggerInterface;

    /** @return Option<ActorRef<object>> */
    public function sender(): Option;

    /**
     * Send a message to another actor, propagating the current correlation context.
     *
     * Use this instead of $ref->tell() when sending from inside a message handler.
     * The outgoing envelope inherits the correlationId of the current message and
     * sets causationId to the current message's requestId.
     *
     * @param ActorRef<object> $ref
     */
    public function tell(ActorRef $ref, object $message): void;

    /**
     * Reply to the sender of the current message, propagating the correlation context.
     *
     * @throws NoSenderException If there is no sender on the current message
     */
    public function reply(object $message): void;

    /**
     * Spawn a background task bound to this actor's lifecycle.
     *
     * The task closure receives a TaskContext for cooperative cancellation
     * and sending messages back to the actor. All spawned tasks are
     * automatically cancelled when the actor stops.
     *
     * @param Closure(TaskContext): void $task
     */
    public function spawnTask(Closure $task): Cancellable;
}
```

### Spawning children

Actors form a hierarchy. Any actor can spawn children, creating a supervised tree.

```php
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;

readonly class StartWorker
{
    public function __construct(public string $name) {}
}

$behavior = Behavior::receive(
    function (ActorContext $ctx, object $msg): Behavior {
        if ($msg instanceof StartWorker) {
            $workerBehavior = Behavior::receive(
                static fn (ActorContext $c, object $m): Behavior => Behavior::same(),
            );
            $child = $ctx->spawn(Props::fromBehavior($workerBehavior), $msg->name);
            $ctx->log()->info('Spawned worker at ' . $child->path());
        }

        return Behavior::same();
    },
);
```

### Watching actors

`watch()` registers the current actor to receive a `Terminated` signal when the target actor stops.

```php
use Monadial\Nexus\Core\Lifecycle\Terminated;
use Monadial\Nexus\Core\Lifecycle\Signal;

$behavior = Behavior::setup(function (ActorContext $ctx): Behavior {
    $child = $ctx->spawn($childProps, 'worker');
    $ctx->watch($child);

    return Behavior::receive(
        static fn (ActorContext $c, object $msg): Behavior => Behavior::same(),
    )->onSignal(
        static function (ActorContext $c, Signal $signal): Behavior {
            if ($signal instanceof Terminated) {
                $c->log()->warning('Child terminated: ' . $signal->ref->path());
            }

            return Behavior::same();
        },
    );
});
```

Use `unwatch()` to stop observing an actor's lifecycle.

### Scheduling messages

Schedule a message to be delivered to `self()` after a delay, or repeatedly at an interval.

```php
readonly class Tick {}

$behavior = Behavior::setup(function (ActorContext $ctx): Behavior {
    $cancellable = $ctx->scheduleRepeatedly(
        Duration::seconds(0),
        Duration::seconds(1),
        new Tick(),
    );

    return Behavior::receive(
        static fn (ActorContext $c, object $msg): Behavior => Behavior::same(),
    );
});
```

The returned `Cancellable` cancels the scheduled task.

```php
$cancellable->cancel();
$cancellable->isCancelled(); // true
```

### Stashing messages

When an actor is not ready to process certain messages (e.g., waiting for initialization), it can stash them and replay later.

```php
readonly class Initialize
{
    public function __construct(public string $config) {}
}

readonly class Work
{
    public function __construct(public string $payload) {}
}

$behavior = Behavior::receive(
    static function (ActorContext $ctx, object $msg): Behavior {
        if ($msg instanceof Initialize) {
            $ctx->unstashAll();

            return Behavior::receive(
                static fn (ActorContext $c, object $m): Behavior => Behavior::same(),
            );
        }

        // Not yet initialized -- stash Work messages for later
        $ctx->stash();

        return Behavior::same();
    },
);
```

### Accessing the sender

`sender()` returns the `ActorRef` of the actor that sent the current message, if available.

```php
$behavior = Behavior::receive(
    static function (ActorContext $ctx, object $msg): Behavior {
        $ctx->sender()->map(
            fn (ActorRef $sender) => $sender->tell(new Ack()),
        );

        return Behavior::same();
    },
);
```

### Sending with ctx->tell()

`$ctx->tell($ref, $message)` is the preferred way to send messages from inside a handler. It propagates tracing IDs automatically (see [Envelopes](#envelope)).

```php
$behavior = Behavior::receive(
    static function (ActorContext $ctx, object $msg): Behavior {
        // Outgoing envelope inherits correlationId and sets causationId = current requestId
        $ctx->tell($downstreamRef, new ProcessOrder($msg->orderId));

        return Behavior::same();
    },
);
```

Calling `$ref->tell()` directly creates a fresh root envelope with a new correlation thread. Use `$ctx->tell()` inside handlers; use `$ref->tell()` for fire-and-forget messages that start a new independent trace.

### Replying to the sender

`$ctx->reply($message)` is shorthand for `$ctx->tell($ctx->sender()->get(), $message)`. It propagates tracing IDs and throws `NoSenderException` if the current message has no sender.

```php
$behavior = Behavior::receive(
    static function (ActorContext $ctx, object $msg): Behavior {
        if ($msg instanceof GetCount) {
            $ctx->reply(new CountReply(42)); // equivalent to ctx->tell(sender, ...)
        }

        return Behavior::same();
    },
);
```

## Envelope

Every message in Nexus is wrapped in an `Envelope` carrying routing and tracing metadata.

```php
final readonly class Envelope
{
    public object $message;       // the actual message object
    public ActorPath $sender;     // path of the sending actor
    public ActorPath $target;     // path of the receiving actor
    public string $requestId;     // unique ULID for this specific message
    public string $correlationId; // shared across all messages in a logical thread
    public string $causationId;   // requestId of the message that triggered this one
    public ?ActorRef $senderRef;  // direct ActorRef — set by ask(), used for replies
    /** @var array<string, string> */
    public array $metadata;
}
```

### Tracing IDs

| ID | Meaning | Changes per message |
|---|---|---|
| `requestId` | Unique ULID for this message | Always — new ULID each time |
| `correlationId` | Conversation thread shared by all causally linked messages | No — inherited from trigger |
| `causationId` | `requestId` of the message that directly triggered this one | Yes — set to trigger's `requestId` |

`Envelope::of()` starts a root envelope — all three IDs are set to the same fresh ULID, beginning a new correlation thread.

`Envelope::causedBy($cause, ...)` creates a causally linked envelope — `requestId` is fresh, `correlationId` is inherited from `$cause`, and `causationId` is set to `$cause->requestId`.

`ActorContext::tell()` and `ActorContext::reply()` call `Envelope::causedBy()` internally, so the trace chain is maintained automatically through all handler-to-handler sends.

## ActorSystem

`ActorSystem` is the entry point for the entire actor hierarchy. It manages the lifecycle of all top-level actors, provides a dead-letter endpoint, and delegates scheduling and concurrency to the injected `Runtime`.

```php
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Runtime\Runtime\Runtime;

$system = ActorSystem::create(
    name: 'my-app',
    runtime: $runtime,
    clock: $clock,           // optional, PSR-20 ClockInterface
    logger: $logger,         // optional, PSR-3 LoggerInterface
    eventDispatcher: $dispatcher, // optional, PSR-14 EventDispatcherInterface
);
```

### Spawning top-level actors

```php
$ref = $system->spawn($props, 'orders');
echo $ref->path(); // "/user/orders"

$anonRef = $system->spawnAnonymous($props);
echo $anonRef->path(); // "/user/auto-0"
```

`spawn()` requires a unique name -- spawning a second actor with the same name throws `ActorNameExistsException`. `spawnAnonymous()` generates a unique name automatically.

### Running and shutting down

```php
// Schedule a graceful shutdown after 500ms
$runtime->scheduleOnce(Duration::millis(500), static function () use ($system): void {
    $system->shutdown(Duration::seconds(10));
});

// Start the runtime event loop (blocks until shutdown)
$system->run();
```

`run()` blocks until the runtime has no more work to do. `shutdown()` sends a `PoisonPill` to all top-level actors, waits for their mailboxes to drain, then signals the runtime to stop.

### Stopping individual actors

```php
$system->stop($ref);
```

This sends a `PoisonPill` to the actor, which triggers a graceful stop after processing any messages ahead of it in the mailbox.

### Dead letters

```php
$deadLetters = $system->deadLetters();
```

Returns the `DeadLetterRef` -- see the [DeadLetterRef](#deadletterref) section below.

### Full example

```php
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Runtime\Duration;

readonly class Ping
{
    public function __construct(public string $from) {}
}

$behavior = Behavior::receive(
    static function (ActorContext $ctx, object $msg): Behavior {
        if ($msg instanceof Ping) {
            $ctx->log()->info("Ping from {$msg->from}");
        }

        return Behavior::same();
    },
);

$system = ActorSystem::create('example', $runtime);
$ref = $system->spawn(Props::fromBehavior($behavior), 'pinger');
$ref->tell(new Ping('main'));

$runtime->scheduleOnce(Duration::millis(500), static function () use ($system): void {
    $system->shutdown(Duration::seconds(5));
});
$system->run();
```

## ActorPath

`ActorPath` is an immutable, fully-qualified address within the actor hierarchy. Paths look like `/user/orders/order-123` and follow a strict naming pattern: segments may contain letters, digits, underscores, hyphens, and dots.

```php
use Monadial\Nexus\Core\Actor\ActorPath;

// Parse from a string
$path = ActorPath::fromString('/user/orders');

// Build paths incrementally
$root = ActorPath::root();                  // "/"
$user = $root->child('user');               // "/user"
$orders = $user->child('orders');           // "/user/orders"
$order = $orders->child('order-123');       // "/user/orders/order-123"

// Navigate the hierarchy
echo $order->name();                        // "order-123"
echo $order->parent()->get();               // "/user/orders"
echo $order->depth();                       // 3
echo ActorPath::root()->depth();            // 0

// Hierarchy checks
$order->isChildOf($orders);                 // true
$order->isChildOf($user);                   // false
$order->isDescendantOf($user);              // true

// Value equality
$a = ActorPath::fromString('/user/orders');
$b = ActorPath::fromString('/user/orders');
$a->equals($b);                            // true

// String conversion (implements Stringable)
echo $order;                                // "/user/orders/order-123"
```

The root path (`/`) returns `Option::none()` from `parent()` and `'/'` from `name()`.

## Class-based actors

While Nexus encourages functional behaviors (closures), it also supports class-based actors for complex actors that benefit from structured code, dependency injection, or lifecycle hooks.

### ActorHandler

`ActorHandler<T>` is the minimal interface for class-based actors. Implement a single `handle()` method that receives the context and message, and returns a `Behavior`.

```php
use Monadial\Nexus\Core\Actor\ActorHandler;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;

readonly class PlaceOrder
{
    public function __construct(public string $orderId, public float $amount) {}
}

readonly class CancelOrder
{
    public function __construct(public string $orderId) {}
}

/** @implements ActorHandler<PlaceOrder|CancelOrder> */
final class OrderActor implements ActorHandler
{
    public function handle(ActorContext $ctx, object $message): Behavior
    {
        return match (true) {
            $message instanceof PlaceOrder => $this->place($ctx, $message),
            $message instanceof CancelOrder => $this->cancel($ctx, $message),
            default => Behavior::unhandled(),
        };
    }

    private function place(ActorContext $ctx, PlaceOrder $msg): Behavior
    {
        $ctx->log()->info("Placing order {$msg->orderId} for \${$msg->amount}");

        return Behavior::same();
    }

    private function cancel(ActorContext $ctx, CancelOrder $msg): Behavior
    {
        $ctx->log()->info("Cancelling order {$msg->orderId}");

        return Behavior::same();
    }
}
```

Spawn it with `Props::fromFactory()`:

```php
$ref = $system->spawn(
    Props::fromFactory(fn () => new OrderActor()),
    'order-processor',
);
```

### AbstractActor

`AbstractActor` extends `ActorHandler` with optional lifecycle hooks: `onPreStart()` and `onPostStop()`. Override them to run initialization or cleanup logic.

```php
use Monadial\Nexus\Core\Actor\AbstractActor;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;

readonly class ProcessJob
{
    public function __construct(public string $payload) {}
}

/** @extends AbstractActor<ProcessJob> */
final class WorkerActor extends AbstractActor
{
    public function onPreStart(ActorContext $ctx): void
    {
        $ctx->log()->info('Worker starting at ' . $ctx->self()->path());
    }

    public function handle(ActorContext $ctx, object $message): Behavior
    {
        if ($message instanceof ProcessJob) {
            $ctx->log()->info("Processing: {$message->payload}");
        }

        return Behavior::same();
    }

    public function onPostStop(ActorContext $ctx): void
    {
        $ctx->log()->info('Worker stopped');
    }
}

$ref = $system->spawn(
    Props::fromFactory(fn () => new WorkerActor()),
    'worker',
);
```

When spawned via `Props::fromFactory()`, lifecycle hooks are wired automatically: `onPreStart()` is called during actor initialization, and `onPostStop()` is called when the actor receives a `PostStop` signal.

### StatefulActorHandler

`StatefulActorHandler<T, S>` is designed for actors that manage explicit state. Instead of closing over mutable variables, the class provides an `initialState()` and receives the current state on each `handle()` call. State updates are returned via `BehaviorWithState`.

```php
use Monadial\Nexus\Core\Actor\StatefulActorHandler;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\BehaviorWithState;

readonly class AddItem
{
    public function __construct(public string $item) {}
}

readonly class GetItems
{
    public function __construct(public ActorRef $replyTo) {}
}

readonly class ItemList
{
    /** @param list<string> $items */
    public function __construct(public array $items) {}
}

/**
 * @implements StatefulActorHandler<AddItem|GetItems, list<string>>
 */
final class CartActor implements StatefulActorHandler
{
    /** @return list<string> */
    public function initialState(): array
    {
        return [];
    }

    public function handle(ActorContext $ctx, object $message, mixed $state): BehaviorWithState
    {
        return match (true) {
            $message instanceof AddItem => BehaviorWithState::next(
                [...$state, $message->item],
            ),
            $message instanceof GetItems => $this->getItems($message, $state),
            default => BehaviorWithState::same(),
        };
    }

    /** @param list<string> $state */
    private function getItems(GetItems $msg, array $state): BehaviorWithState
    {
        $msg->replyTo->tell(new ItemList($state));

        return BehaviorWithState::same();
    }
}

$ref = $system->spawn(
    Props::fromStatefulFactory(fn () => new CartActor()),
    'cart',
);
```

## DeadLetterRef

`DeadLetterRef` is a special `ActorRef` implementation that captures messages sent to actors that are no longer alive or to invalid references. It serves as the system's catch-all for undeliverable messages.

```php
use Monadial\Nexus\Core\Actor\DeadLetterRef;

$deadLetters = $system->deadLetters();

// isAlive() always returns false
$deadLetters->isAlive(); // false

// tell() captures the message instead of delivering it
$deadLetters->tell(new SomeMessage());

// ask() always throws AskTimeoutException
$deadLetters->ask($factory, Duration::seconds(1)); // throws AskTimeoutException

// Retrieve captured messages (useful for testing and debugging)
$captured = $deadLetters->captured(); // list<object>

// Path is always /system/deadLetters
echo $deadLetters->path(); // "/system/deadLetters"
```

Messages that cannot be delivered — for example, because the target actor has stopped — are routed to dead letters. The `captured()` list is useful in tests to verify that no messages were lost unexpectedly.
