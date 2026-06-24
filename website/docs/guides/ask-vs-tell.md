---
sidebar_position: 2
title: How to choose between ask and tell
related:
  - core-concepts/ask-pattern
  - core-concepts/actors
  - core-concepts/behaviors
  - guides/message-design
---

# How to choose between ask and tell

When your actor sends a message to another actor, you must decide whether to wait for the reply or continue immediately.

## Solution

The rule is: **`tell()` by default. `ask()` only when the caller's response depends on the answer.**

```php title="src/Http/Handler/LedgerRecordHandler.php"
// tell() — fire-and-forget; the HTTP handler returns immediately
$this->ledgerFactory->of($principal->id())
    ->tell(new RecordLedger($body->kind, $body->amountCents));

return JsonResponse::ok(new LedgerRecordResponse(
    ownerId: $principal->id(),
    kind: $body->kind,
    amountCents: $body->amountCents,
));
```

```php title="src/Http/Handler/DepositHandler.php"
// ask() — the HTTP response IS the actor's reply
$reply = $walletRef->ref
    ->ask(new Deposit(new Money($body->amountCents)), Duration::seconds(2))
    ->await();

assert($reply instanceof DepositResult);

return JsonResponse::ok(new WalletOperationResponse(
    ownerId: $principal->id(),
    accepted: $reply->accepted,
    balance: $reply->balanceCents,
));
```

## How it works

`tell()` enqueues the message and returns immediately — the caller has no access to the result. `ask()` returns a `Future<R>`; calling `->await()` suspends the calling fiber or coroutine until the actor replies or the timeout fires.

Use `tell()` when the work happens asynchronously and the caller has nothing to do with the result: background side effects, event publication, audit writes. Use `ask()` when the caller's next action depends on the answer — current balance after a deposit, business validation, generated identifiers.

## Variations

### Blocking inside an actor handler

Calling `ask()->await()` inside an actor's message handler suspends that actor's fiber. The actor cannot process other messages while it waits. If the call chain is deep and the pool has fewer coroutine slots than chain length, you deadlock.

:::caution Don't block inside a handler
```php title="src/Actor/OrderActor.php" verify:lint-only
public function handle(ActorContext $ctx, object $msg): Behavior
{
    if ($msg instanceof ProcessOrder) {
        // actor's fiber is stuck here — cannot process other messages
        $payment = $this->paymentService
            ->ask(new Charge(/* ... */), Duration::seconds(5))
            ->await();
    }
}
```
:::

The correct approach is to `tell()` and return, then handle the reply as a normal message:

```php title="src/Actor/OrderActor.php"
public function handle(ActorContext $ctx, object $msg): Behavior
{
    return match (true) {
        $msg instanceof ProcessOrder  => $this->beginPayment($ctx, $msg),
        $msg instanceof PaymentDone   => $this->finishOrder($ctx, $msg),
        $msg instanceof PaymentFailed => $this->rejectOrder($ctx, $msg),
        default                       => Behavior::unhandled(),
    };
}

private function beginPayment(ActorContext $ctx, ProcessOrder $msg): Behavior
{
    $this->paymentService->tell(new Charge(replyTo: $ctx->self()));
    return Behavior::same();
}
```

The actor processes other orders while the payment runs asynchronously. The reply arrives as a normal message and the state machine handles it. This is the stateful workflow pattern — the correct shape for any multi-step actor process.

### Parallel asks with `Future::all`

When an actor genuinely needs results from multiple independent services before it can reply, gather them without blocking:

```php title="src/Actor/ProfileActor.php"
return Future::all([
    'user'   => $this->users->ask(new GetUser($id), Duration::seconds(1)),
    'orders' => $this->orders->ask(new ListByUser($id), Duration::seconds(1)),
])->map(static fn(array $parts) => new ProfileResponse(
    user: $parts['user'],
    orders: $parts['orders'],
));
```

The handler returns the `Future` directly. The framework awaits it; other messages on the same coroutine pool make progress in the meantime.

### Buffering with stash while waiting

When the actor must pause processing of other messages until a reply arrives, use `stash()` to buffer them and `unstashAll()` to drain when ready:

```php title="src/Actor/PaymentActor.php"
private function awaitingPayment(ActorContext $ctx): Behavior
{
    return Behavior::receive(static function ($ctx, $msg) use (&$ready): Behavior {
        if ($msg instanceof PaymentDone || $msg instanceof PaymentFailed) {
            $ctx->unstashAll();
            return $ready;
        }

        $ctx->stash();
        return Behavior::same();
    });
}
```

Other messages stash in arrival order and replay when the actor returns to its normal behavior.

## Caveats

- `ask()` requires a `Duration` timeout. A useful default is p99 expected reply time × 3. Too short causes false `AskTimeoutException` failures under load; too long ties up coroutine slots waiting for dead actors.
- If you want "wait forever," you do not want `ask()`. Use `tell()` with a reply message and let the caller's lifecycle (HTTP request timeout, supervision) bound the wait.
- `ask()` from the HTTP boundary is fine. `ask()` from inside another actor's handler is almost always a design smell.

## Related

- [Ask pattern](../core-concepts/ask-pattern.md)
- [Behaviors](../core-concepts/behaviors.md)
- [Message design](message-design.md)
