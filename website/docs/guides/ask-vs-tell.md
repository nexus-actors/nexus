---
sidebar_position: 7
title: Ask vs tell
---

# Ask vs tell

> *"How do I know whether to wait for the reply or just fire and
> forget?"*

The mechanical question is "does the caller need to see the result
before continuing?" The honest version is "is the caller's
correctness contingent on the actor having processed this message?"

## The rule

> **`tell()` by default. `ask()` only when the caller's response
> depends on the answer.**

`tell()` is fire-and-forget. The message lands in the mailbox; the
caller doesn't wait. It's how you'd write a queue producer.

`ask()` returns a `Future<R>`. Calling `->await()` blocks the
calling fiber/coroutine until either the actor replies or the
timeout fires. It's how you'd write a synchronous RPC.

Both have a role; the question is *which*.

## Use `tell` when

- The work happens asynchronously and the HTTP response doesn't carry
  its outcome. (Background side effects, event publication, audit.)
- The caller has nothing useful to do with the response. (`LedgerActor`
  receives `RecordLedger` from the HTTP handler — the handler returns
  the *request* it accepted, not the resulting database row.)
- The actor's job is a side effect and the protocol is one-way.

Example — the wallet-app's `LedgerRecordHandler`:

```php
$this->ledgerFactory->of($principal->id())
    ->tell(new RecordLedger($body->kind, $body->amountCents));

return JsonResponse::ok(new LedgerRecordResponse(
    ownerId: $principal->id(),
    kind: $body->kind,
    amountCents: $body->amountCents,
));
```

The handler returns `status: recorded` without waiting for the actor
to flush. The actor's mailbox + supervision guarantee the work
happens; the user gets a fast acceptance.

## Use `ask` when

- The HTTP response includes data computed by the actor (current
  balance after a deposit, generated id, business validation).
- The caller's next decision depends on the reply (rejection vs
  acceptance, conflict detection).
- The protocol is request-response by design.

Example — the wallet-app's `DepositHandler`:

```php
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

The HTTP response IS the actor's reply; there's no other way to get
it.

## Don't `ask` from inside another actor's handler

This is the trap that catches people coming from threaded systems.

```php
// BAD — inside an actor's handler.
public function handle(ActorContext $ctx, object $msg): Behavior
{
    if ($msg instanceof ProcessOrder) {
        $payment = $this->paymentService->ask(new Charge(/* … */), Duration::seconds(5))
                         ->await();   // ← actor stuck here
        // …
    }
}
```

The actor's coroutine/fiber is suspended waiting on the reply. The
actor can't process other messages. If the chain depth is N and the
pool has fewer than N coroutine slots, you deadlock.

The fix is to `tell` and continue, then handle the reply as a
*future* message:

```php
public function handle(ActorContext $ctx, object $msg): Behavior
{
    return match (true) {
        $msg instanceof ProcessOrder => $this->beginPayment($ctx, $msg),
        $msg instanceof PaymentDone  => $this->finishOrder($ctx, $msg),
        $msg instanceof PaymentFailed => $this->rejectOrder($ctx, $msg),
    };
}

private function beginPayment(ActorContext $ctx, ProcessOrder $msg): Behavior
{
    $this->paymentService->tell(new Charge(/* … */, replyTo: $ctx->self()));
    return Behavior::same();   // actor returns to the mailbox
}
```

The actor processes other orders while payment processes asynchronously;
the reply (`PaymentDone` or `PaymentFailed`) arrives as a normal
message and the state machine handles it.

This is the **stateful workflow** pattern. Almost every multi-step
actor process should be shaped this way.

## When you DO need to wait inside an actor

Sometimes the actor genuinely can't do anything without the reply.
Two escape hatches:

**1. `Future::all` for parallel work.** Multiple `ask` to independent
services, joined non-blockingly:

```php
return Future::all([
    'user'   => $this->users->ask(new GetUser($id), Duration::seconds(1)),
    'orders' => $this->orders->ask(new ListByUser($id), Duration::seconds(1)),
])->map(static fn(array $parts) => new ProfileResponse(/* … */));
```

The handler returns a `Future<ProfileResponse>`. The framework awaits
it; other messages on the same coroutine pool make progress in the
meantime.

**2. `Behavior::receive` with stash + unstash.** Buffer incoming
messages while you wait for the reply, then drain them when ready:

```php
private function beginPayment(ActorContext $ctx, ProcessOrder $msg): Behavior
{
    $this->paymentService->tell(new Charge(/* … */, $ctx->self()));

    return Behavior::receive(static function ($ctx, $msg) use ($order): Behavior {
        if ($msg instanceof PaymentDone || $msg instanceof PaymentFailed) {
            $ctx->unstashAll();
            return $this->ready;
        }

        $ctx->stash();
        return Behavior::same();
    });
}
```

The actor accepts the payment-result message immediately; other
messages stash until the actor returns to normal behaviour. Order is
preserved.

## Timeouts are not optional

`ask` requires a `Duration` timeout. Pick deliberately.

- Too short → false `AskTimeoutException` under load
- Too long → callers tie up coroutine slots waiting for already-dead
  actors

A useful default: **p99 expected reply time × 3**. Round up for
networked transports; round down for in-process actors.

If you'd genuinely prefer "wait forever", you don't want `ask`. Use
`tell` + a callback message, and let the caller's lifecycle (HTTP
request timeout, supervisor) bound the wait.
