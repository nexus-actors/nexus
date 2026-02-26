---
sidebar_position: 7
title: Ask Pattern
---

# Ask Pattern

The ask pattern lets you send a message to an actor and get a `Future` for the
reply. It bridges the actor world (asynchronous, message-driven) with code that
needs a response.

## Signature

```php
/**
 * @template R of object
 * @param T $message
 * @return Future<R>
 * @throws AskTimeoutException
 */
#[NoDiscard]
public function ask(object $message, Duration $timeout): Future;
```

The method is defined on the `ActorRef` interface. It is marked `#[NoDiscard]`,
so static analysis tools will warn if you ignore the return value.

## How it works

1. `ask()` creates a `FutureSlot` (runtime-specific suspension primitive) and a
   lightweight `FutureRef` — a single-use `ActorRef` whose `tell()` resolves the
   slot.
2. The message is enqueued to the target actor's mailbox immediately, with the
   `FutureRef` carried as the envelope's `senderRef`.
3. The target actor processes the message and calls `$ctx->reply($response)`,
   which routes the response back through the `FutureRef`.
4. `Future::await()` suspends the current fiber/coroutine until the reply
   arrives or the timeout fires.
5. If the timeout expires before a reply, `AskTimeoutException` is thrown.

```
  Caller                    Target Actor
    │                            │
    │  ask($msg, $timeout)       │
    │──── Future ◄───────────────│
    │                            │
    │  enqueue(msg + FutureRef)  │
    │───────────────────────────►│
    │                            │
    │  $future->await()          │  ctx->reply($response)
    │  ┊ (fiber suspends)        │──────────────────────►│
    │  ┊                         │                FutureRef::tell()
    │  ┊                         │                FutureSlot::resolve()
    │◄─┊─────────────────────────│                       │
    │  $response                 │
```

## Usage

```php
use Monadial\Nexus\Runtime\Duration;

// Request message — no replyTo field needed
final readonly class GetBalance {}

final readonly class Balance {
    public function __construct(public float $amount) {}
}

// Ask the account actor for its balance
$future = $accountRef->ask(new GetBalance(), Duration::seconds(5));
$balance = $future->await();

// $balance is a Balance instance
echo $balance->amount;
```

The target actor replies via `$ctx->reply()`:

```php
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\ActorContext;

$behavior = Behavior::receive(
    static function (ActorContext $ctx, object $msg): Behavior {
        if ($msg instanceof GetBalance) {
            $ctx->reply(new Balance(amount: 42.50));

            return Behavior::same();
        }

        return Behavior::unhandled();
    },
);
```

Notice that request messages no longer need a `$replyTo` field. The reply is
routed automatically through the envelope's sender reference.

## Future combinators

`Future` supports `map()` and `flatMap()` for transforming results without
blocking:

### map — transform the reply

```php
$future = $accountRef->ask(new GetBalance(), Duration::seconds(5));

$formatted = $future->map(static function (object $balance): object {
    assert($balance instanceof Balance);

    return new FormattedBalance(
        display: number_format($balance->amount, 2) . ' EUR',
    );
});

$result = $formatted->await(); // FormattedBalance
```

`map()` is lazy — the transformation runs only when `await()` is called.

### flatMap — chain a dependent ask

```php
$future = $accountRef
    ->ask(new GetBalance(), Duration::seconds(5))
    ->flatMap(static function (object $balance) use ($exchangeRef): Future {
        assert($balance instanceof Balance);

        return $exchangeRef->ask(
            new ConvertCurrency($balance->amount, 'USD'),
            Duration::seconds(5),
        );
    });

$converted = $future->await(); // ConvertedAmount
```

`flatMap()` is also lazy. The second ask fires only when the first resolves.

## Timeout handling

If no reply arrives within the specified `Duration`, `await()` throws
`AskTimeoutException`:

```php
use Monadial\Nexus\Core\Exception\AskTimeoutException;
use Monadial\Nexus\Runtime\Duration;

try {
    $balance = $accountRef
        ->ask(new GetBalance(), Duration::seconds(3))
        ->await();
} catch (AskTimeoutException $e) {
    // $e->target  -- ActorPath of the actor that did not reply
    // $e->timeout -- the Duration that elapsed
    $logger->warning("Ask to {$e->target} timed out after {$e->timeout}");
}
```

## When to use ask vs tell

| | `tell()` | `ask()` |
|---|---|---|
| **Direction** | Fire-and-forget | Request-response |
| **Blocking** | No | Yes (blocks the calling fiber/coroutine on `await()`) |
| **Return** | `void` | `Future<R>` |
| **Throughput** | Higher | Lower (allocates FutureSlot, suspends fiber) |
| **Coupling** | Loose — sender does not wait | Tighter — sender depends on timely response |
| **Error model** | Failures handled by supervision | Failures surface as `AskTimeoutException` |

**Prefer `tell()` for most actor-to-actor communication.** It keeps actors
decoupled and maximizes throughput. The ask pattern is best reserved for:

- **Edge of the actor system** — when non-actor code (HTTP controllers, CLI
  commands) needs a result from an actor.
- **Orchestration** — when a coordinating actor must gather responses from
  several children before proceeding.
- **Testing** — to assert that an actor produces the expected reply.

When actors need to exchange results with each other, consider using `tell()`
with `$ctx->reply()` in both directions instead of `ask()`. This avoids
blocking and keeps the system fully asynchronous.

## Runtime support

Each runtime provides its own `FutureSlot` implementation:

| Runtime | FutureSlot | Suspension mechanism |
|---|---|---|
| **Fiber** | `FiberFutureSlot` | `Fiber::suspend()` / resume on next tick |
| **Swoole** | `SwooleFutureSlot` | `Swoole\Coroutine\Channel(1)` |
| **Step** | `StepFutureSlot` | `Fiber::suspend()` (deterministic, test-controlled) |

The `FutureSlot` is created by `Runtime::createFutureSlot()`, which also
schedules the timeout timer automatically.
