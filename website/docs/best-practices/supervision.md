---
sidebar_position: 5
title: Supervision and let-it-crash
---

# Supervision and let-it-crash

> *"Why do Erlang and Akka people talk about crashing like it's a
> design choice?"*

Because if you can isolate the blast radius of a failure to one
actor, and you can guarantee a clean restart, then you don't need to
write the defensive code that's normally interleaved with your
business logic. The supervisor does it once, declaratively.

## The instinct to fight

Most PHP developers' instinct is:

```php
try {
    $this->doTheWork($command);
} catch (Throwable $e) {
    $this->logger->error('failed', ['exception' => $e]);
    return Failure::of('something went wrong');
}
```

Inside an actor, that's the wrong shape. The catch-all hides the
failure from supervision, lets corrupt state survive ("we caught it!
keep going!"), and forces every handler to reinvent the recovery
policy.

The right shape is:

```php
public function handle(ActorContext $ctx, object $message): Behavior
{
    // No try/catch. Let it crash.
    return match (true) {
        $message instanceof Deposit => $this->doDeposit($message),
        // …
    };
}
```

…and a parent-level supervision strategy that says "restart on
`TransientError`, escalate on `FatalError`".

## The strategies

```php
$strategy = SupervisionStrategy::exponentialBackoff(
    initialBackoff: Duration::millis(100),
    maxBackoff:     Duration::seconds(30),
    maxRetries:     5,
    multiplier:     2.0,
    decider: static fn(Throwable $e): Directive => match (true) {
        $e instanceof TransientError    => Directive::Restart,
        $e instanceof DomainViolation   => Directive::Stop,
        $e instanceof InfrastructureGone => Directive::Escalate,
        default                          => Directive::Restart,
    },
);

$props = Props::fromBehavior($behavior)->withSupervision($strategy);
```

Three first-class strategies; the same `decider` closure pattern in
each:

- **`oneForOne`** — restart only the failed child. Use this for
  independent children (per-owner aggregates).
- **`allForOne`** — restart every sibling. Use this when children
  share state and one corrupt child means the others can't be
  trusted (a coordinator + workers pattern).
- **`exponentialBackoff`** — restart with growing delay between
  attempts. Use this when the failure is likely transient
  (network blip, database flap).

The `decider` is yours. Match on exception type and return:

| Directive | Meaning |
|---|---|
| `Restart` | Reset the actor, replay PreStart, keep the mailbox |
| `Stop`    | Stop permanently; messages go to dead letters |
| `Resume`  | Pretend the message never happened; keep state |
| `Escalate` | Re-throw to the supervisor's supervisor |

## When to catch

There are legitimate places to `catch`:

1. **At the system boundary** — turning a Doctrine exception into a
   domain `DepositRejected`. That's not error handling, it's
   translation. The actor still returns a typed result; the
   supervisor never sees the exception.
2. **For idempotency** — catching `UniqueConstraintViolationException`
   on a retry and treating it as success. The constraint already says
   "you've done this," so the catch is a domain decision.
3. **In tests** — `expectException()` is fine; the supervisor doesn't
   run in unit tests anyway.

You should NOT catch:

- **Anything you re-throw immediately.** That's not handling, that's
  noise. Let it propagate.
- **`Throwable`.** If you're catching `Throwable`, ask whether you're
  fighting the supervisor.
- **Exceptions for control flow.** If `MailboxClosedException` is part
  of your business logic, your design needs another look.

## Restart vs Resume

`Restart` resets the actor: state goes back to initial, PreStart
fires again, the mailbox keeps its tail. Use this when the actor's
state might be corrupt after a failure (most of the time).

`Resume` keeps the actor's state and just drops the offending message.
Use this when the failure is in the message, not the actor — bad input
that the actor couldn't handle but shouldn't make it forget its
balance.

A good default for `Restart` is "the exception class doesn't say
anything about state corruption" — i.e. probably-fine-but-be-safe.

## The supervisor decides, not the actor

The actor's handler should not contain restart logic. That's the
supervisor's job. If your handler is reasoning about "have I crashed
3 times yet?", you've internalised what the strategy already does
for you.

The actor's handler should:
- transform message + state → new state + side effects,
- decide what to reply, if anything,
- throw on anything it can't handle.

That's it. Retry budgets, backoff timing, escalation paths — all
declared once, on the `Props`, when you spawn.

## What about my HTTP request?

The HTTP request that triggered the crash still sees an error. The
supervisor restarts the actor for the *next* message, not the failed
one. Map your exceptions to HTTP status codes via
`$app->onException(MyException::class, fn() => Response::badRequest())`
and the requester gets a sensible response while the actor recovers
for the next attempt.

If the request can wait, design the protocol around `ask` returning a
typed result (`OK | Rejected`) rather than throwing — the rejection
is a normal value, supervision is for the *unexpected* failures.
