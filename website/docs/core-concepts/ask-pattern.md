---
sidebar_position: 7
title: Ask Pattern
---

# Ask Pattern

The ask pattern lets you send a message to an actor and wait for a reply. It
bridges the actor world (asynchronous, message-driven) with code that needs a
synchronous response.

## Signature

```php
/**
 * @template R of object
 * @param callable(ActorRef<R>): T $messageFactory
 * @return R
 * @throws AskTimeoutException
 */
#[NoDiscard]
public function ask(callable $messageFactory, Duration $timeout): object;
```

The method is defined on the `ActorRef` interface. It is marked `#[NoDiscard]`,
so static analysis tools will warn if you ignore the return value.

## How it works

1. `ask()` creates a temporary, short-lived `ActorRef<R>` -- the "reply-to" ref.
2. Your `$messageFactory` receives this ref and must return the message to send
   to the target actor.
3. The target actor processes the message and calls `$replyTo->tell($response)`.
4. `ask()` blocks the current fiber/coroutine until the response arrives or the
   timeout elapses.
5. If the timeout expires before a reply, `AskTimeoutException` is thrown.

## Usage

```php
use Monadial\Nexus\Core\Duration;

// Define a request message that carries a reply-to ref
final readonly class GetBalance {
    public function __construct(public ActorRef $replyTo) {}
}

final readonly class Balance {
    public function __construct(public float $amount) {}
}

// Ask the account actor for its balance
$balance = $accountRef->ask(
    fn(ActorRef $replyTo) => new GetBalance($replyTo),
    Duration::seconds(5),
);

// $balance is a Balance instance
echo $balance->amount;
```

The target actor's handler sends the reply:

```php
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\ActorContext;

$behavior = Behavior::receive(
    function (ActorContext $ctx, object $msg): Behavior {
        if ($msg instanceof GetBalance) {
            $msg->replyTo->tell(new Balance(amount: 42.50));
            return Behavior::same();
        }
        return Behavior::unhandled();
    },
);
```

## Timeout handling

If no reply arrives within the specified `Duration`, `ask()` throws
`AskTimeoutException`:

```php
use Monadial\Nexus\Core\Exception\AskTimeoutException;
use Monadial\Nexus\Core\Duration;

try {
    $result = $ref->ask(
        fn(ActorRef $replyTo) => new GetBalance($replyTo),
        Duration::seconds(3),
    );
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
| **Blocking** | No | Yes (blocks the calling fiber/coroutine) |
| **Throughput** | Higher | Lower (creates a temporary actor, waits for reply) |
| **Coupling** | Loose -- sender does not wait for the receiver | Tighter -- sender depends on timely response |
| **Error model** | Failures handled by supervision | Failures surface as `AskTimeoutException` at call site |

**Prefer `tell()` for most actor-to-actor communication.** It keeps actors
decoupled and maximizes throughput. The ask pattern is best reserved for:

- **Edge of the actor system** -- when non-actor code (HTTP controllers, CLI
  commands) needs a result from an actor.
- **Orchestration** -- when a coordinating actor must gather responses from
  several children before proceeding.
- **Testing** -- to assert that an actor produces the expected reply.

When actors need to exchange results with each other, consider passing a
`replyTo: ActorRef` field in the message and using `tell()` in both directions
instead of `ask()`. This avoids blocking and keeps the system fully asynchronous.

## Extracting the messageFactory pattern

A common convention is to define the factory as a static method on the request
message class:

```php
final readonly class GetBalance {
    public function __construct(public ActorRef $replyTo) {}

    /**
     * @return callable(ActorRef<Balance>): self
     */
    public static function factory(): callable
    {
        return fn(ActorRef $replyTo) => new self($replyTo);
    }
}

// Usage becomes:
$balance = $accountRef->ask(GetBalance::factory(), Duration::seconds(5));
```

This keeps the message factory close to the message definition and reduces
boilerplate at call sites.
