---
sidebar_position: 7
title: Common mistakes
related:
  - getting-started/quick-start
  - core-concepts/behaviors
  - core-concepts/actors
  - core-concepts/ask-pattern
---

# Common mistakes

This page catalogs the ten most frequent day-one mistakes when writing Nexus actors, with the exact error or symptom and the fix for each.

---

## 1. Message class is not `readonly`

Nexus messages are passed by reference between actors. A mutable message creates a shared-state race condition.

The Psalm plugin (`ReadonlyMessageRule`) flags this at static analysis time, but you may see it first as unexpected behavior at runtime.

:::caution Missing `readonly` silently allows mutation
A non-readonly message can be modified by one actor after another actor has already started reading it.
:::

```php title="src/Messages/Increment.php" verify:lint-only
// Wrong — mutable message
class Increment
{
    public int $amount = 1;
}
```

```php title="src/Messages/Increment.php"
// Correct — readonly class
readonly class Increment
{
    public function __construct(public int $amount) {}
}
```

---

## 2. Constructing a message and forgetting to `tell()` it

Creating a message object with `new` has no effect. Messages must be delivered via `tell()` on an `ActorRef`.

```php title="src/App.php" verify:lint-only
// Wrong — the message is constructed but never sent
$msg = new Increment(1);
```

```php title="src/App.php"
// Correct — deliver through the actor reference
$ref->tell(new Increment(1));
```

---

## 3. Wrong `Duration` namespace

`Duration` lives in `Monadial\Nexus\Runtime\Duration`, not in the core package. Importing the wrong namespace produces a class-not-found error.

```php title="src/Actor/MyActor.php" verify:lint-only
// Wrong namespace
use Monadial\Nexus\Core\Duration;
```

```php title="src/Actor/MyActor.php"
// Correct namespace
use Monadial\Nexus\Runtime\Duration;

$timeout = Duration::seconds(5);
```

---

## 4. Using `ask()` without reading the result

`ask()` is asynchronous. The returned future suspends the current fiber until the reply arrives or the timeout expires. If you discard the return value, no value is ever obtained and the fiber resumes immediately with no result.

:::caution `ask()` without `->await()` returns a future, not the reply
The actor has not replied yet when `ask()` returns. Call `->await()` to block until the reply arrives.
:::

<!-- verify:skip: requires a running actor system with an actor registered at $ref -->
```php title="src/Handler/CountHandler.php" verify:skip
// Wrong — discards the future
$ref->ask(fn ($replyTo) => new GetCount($replyTo), Duration::seconds(5));

// Correct — await the reply
$count = $ref->ask(fn ($replyTo) => new GetCount($replyTo), Duration::seconds(5))->await();
```

---

## 5. Cross-worker message not registered with `#[MessageType]`

When an actor sends a message to a `WorkerActorRef` (a cross-thread reference), the message class must carry a `#[MessageType]` attribute. Without it, the serializer cannot map the class name to a stable wire-format type name, and the Psalm plugin (`NonSerializableRemoteMessageRule`) will fail.

```php title="src/Messages/Transfer.php" verify:lint-only
// Wrong — missing attribute for cross-worker message
readonly class Transfer
{
    public function __construct(public int $amount) {}
}
```

```php title="src/Messages/Transfer.php"
use Monadial\Nexus\Serialization\MessageType;

// Correct — stable wire type name registered
#[MessageType('wallet.transfer')]
readonly class Transfer
{
    public function __construct(public int $amount) {}
}
```

---

## 6. Capturing by reference in `Props::fromFactory()`

The `Props::fromFactory()` closure is invoked once per actor spawn. If it captures variables by reference (`&$var`), the same object is shared across spawned actors — breaking the actor isolation guarantee. The Psalm plugin (`MutableClosureCaptureRule`) catches this.

```php title="src/App.php" verify:lint-only
// Wrong — $repository is captured by reference
$repository = new OrderRepository();
$props = Props::fromFactory(fn () => new OrderActor($repository));
// correct PHP syntax but $repository is shared state
```

```php title="src/App.php"
// Correct — factory creates a fresh instance per spawn
$props = Props::fromFactory(fn () => new OrderActor(new OrderRepository()));
```

---

## 7. Blocking call inside a message handler

Calling blocking I/O functions inside a handler (`file_get_contents`, `curl_exec`, `sleep`, PDO queries with no async driver) stalls the fiber scheduler. No other actor in the same runtime runs until the blocking call returns. The Psalm plugin (`BlockingCallInHandlerRule`) flags known blocking calls.

:::caution Blocking in a handler starves all other actors on that fiber scheduler
One slow HTTP call in a handler can delay all messages across the entire actor system.
:::

```php title="src/Actor/FetchActor.php" verify:lint-only
// Wrong — blocking HTTP call inside handler
public function handle(ActorContext $ctx, object $message): Behavior
{
    $data = file_get_contents('https://api.example.com/data');
    return Behavior::same();
}
```

The correct pattern is to offload the I/O to a child actor or a Swoole coroutine, then reply when complete.

---

## 8. Hand-rolling a timeout instead of using `Duration`

A common pattern is to add `sleep()` or `time()`-based logic to simulate timeouts. This blocks the fiber and bypasses the actor scheduler.

```php title="src/Actor/TimerActor.php" verify:lint-only
// Wrong — blocks the fiber scheduler
sleep(5);
```

```php title="src/Actor/TimerActor.php"
// Correct — non-blocking scheduled callback
$ctx->scheduleOnce(Duration::seconds(5), new CheckTimeout());
```

---

## 9. Mutating state inside `Behavior::receive()`

`Behavior::receive()` is a stateless behavior. Any state you mutate via a closed-over variable is shared across all messages and across re-spawns. Use `Behavior::withState()` to thread state correctly.

```php title="src/Actor/CounterActor.php" verify:lint-only
// Wrong — mutable closed-over variable
$count = 0;
$behavior = Behavior::receive(static function ($ctx, object $msg) use (&$count): Behavior {
    if ($msg instanceof Increment) {
        $count++;   // mutates external state — breaks isolation
    }
    return Behavior::same();
});
```

```php title="src/Actor/CounterActor.php"
use Monadial\Nexus\Core\Actor\BehaviorWithState;

// Correct — state is threaded through BehaviorWithState
$behavior = Behavior::withState(
    0,
    static function ($ctx, object $msg, int $count): BehaviorWithState {
        if ($msg instanceof Increment) {
            return BehaviorWithState::next($count + 1);
        }

        return BehaviorWithState::same();
    },
);
```

---

## 10. Forgetting to return `Behavior::same()` from a handler

Every message handler must return a `Behavior`. Omitting the return (or returning `null`) causes a type error at runtime. The most common form is a handler with no explicit `return` at the end.

```php title="src/Actor/GreeterActor.php" verify:lint-only
// Wrong — missing return value
$behavior = Behavior::receive(static function ($ctx, object $msg): Behavior {
    if ($msg instanceof Greet) {
        echo 'Hello, ' . $msg->name;
    }
    // no return — Behavior::same() is not implied
});
```

```php title="src/Actor/GreeterActor.php"
use Monadial\Nexus\Core\Actor\Behavior;

// Correct — always return a Behavior
$behavior = Behavior::receive(static function ($ctx, object $msg): Behavior {
    if ($msg instanceof Greet) {
        echo 'Hello, ' . $msg->name;
    }

    return Behavior::same();
});
```

## Next steps

- [Behaviors](../core-concepts/behaviors.md) — understand `Behavior::receive()`, `withState()`, and `setup()`.
- [Ask pattern](../core-concepts/ask-pattern.md) — request-reply with `ask()` and futures.
- [Quick start](./quick-start.md) — a complete working counter actor from scratch.
