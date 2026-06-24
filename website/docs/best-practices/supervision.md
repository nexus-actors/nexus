---
sidebar_position: 5
title: Supervision and let-it-crash
related:
  - core-concepts/supervision
  - best-practices/when-to-use-actors
  - best-practices/passivation
  - core-concepts/lifecycle
---

# Supervision and let-it-crash

If you can isolate a failure to one actor and guarantee a clean restart, you don't need the defensive code that's normally interleaved with your business logic — the supervisor does it once, declaratively.

## The instinct to fight

Most PHP developers reach for this inside an actor handler:

```php title="src/Actor/BadHandler.php" verify:lint-only
try {
    $this->doTheWork($command);
} catch (Throwable $e) {
    $this->logger->error('failed', ['exception' => $e]);
    return Failure::of('something went wrong');
}
```

That catch-all hides the failure from supervision, lets corrupt state survive, and forces every handler to reinvent the recovery policy. The supervisor never gets a chance to act.

## The right shape

```php title="src/Actor/LedgerActor.php"
public function handle(ActorContext $ctx, object $message): Behavior
{
    return match (true) {
        $message instanceof Deposit  => $this->doDeposit($message),
        $message instanceof Withdraw => $this->doWithdraw($message),
        default                      => Behavior::unhandled(),
    };
}
```

No `try/catch`. Let it crash. Declare the recovery policy on the `Props` when you spawn:

```php title="src/Bootstrap/ActorBootstrap.php"
$strategy = SupervisionStrategy::exponentialBackoff(
    initialBackoff: Duration::millis(100),
    maxBackoff:     Duration::seconds(30),
    maxRetries:     5,
    multiplier:     2.0,
    decider: static fn(Throwable $e): Directive => match (true) {
        $e instanceof TransientError     => Directive::Restart,
        $e instanceof DomainViolation    => Directive::Stop,
        $e instanceof InfrastructureGone => Directive::Escalate,
        default                          => Directive::Restart,
    },
);

$props = Props::fromBehavior($behavior)->withSupervision($strategy);
```

## The three strategies

- **`oneForOne`** — restart only the failed child. Use for independent children (per-owner aggregates).
- **`allForOne`** — restart every sibling. Use when children share state and one corrupt child means the others can't be trusted (coordinator + workers pattern).
- **`exponentialBackoff`** — restart with growing delay between attempts. Use when the failure is likely transient (network blip, database flap).

## Directives

| Directive | Meaning |
|---|---|
| `Restart` | Reset the actor, replay `PreStart`, keep the mailbox |
| `Stop` | Stop permanently; messages go to dead letters |
| `Resume` | Drop the offending message; keep state |
| `Escalate` | Re-throw to the supervisor's supervisor |

Prefer `Restart` over `Resume` by default. `Resume` keeps the actor's state intact — use it only when you're certain the failure is in the message, not the actor's accumulated state.

## When to catch exceptions

There are legitimate places to `catch` inside an actor handler:

1. **At the system boundary** — translating a Doctrine exception into a domain `DepositRejected`. That's not error handling; it's translation. The supervisor never sees the exception.
2. **For idempotency** — catching `UniqueConstraintViolationException` on a retry and treating it as success. The constraint already says the work was done.
3. **In tests** — `expectException()` is fine; the supervisor doesn't run in unit tests anyway.

Do not catch:

- Anything you re-throw immediately. That's noise, not handling.
- `Throwable` as a blanket. If you're catching `Throwable`, you're fighting the supervisor.
- Exceptions for control flow. If `MailboxClosedException` is part of your business logic, the design needs revisiting.

## The supervisor decides, not the actor

The actor's handler should not contain restart logic. If your handler is reasoning about "have I crashed three times yet?", you've internalised what `SupervisionStrategy` already does for you.

The actor's handler has one job: transform message + state into new state + side effects, decide what to reply, and throw on anything it can't handle. Retry budgets, backoff timing, and escalation paths are declared once on the `Props` when you spawn.

## Mapping crashes to HTTP responses

The HTTP request that triggered the crash still sees an error. The supervisor restarts the actor for the *next* message, not the failed one. Map exceptions to HTTP status codes at the application boundary:

```php title="src/Bootstrap/AppBootstrap.php"
$app->onException(
    DomainViolation::class,
    static fn(DomainViolation $e) => Response::badRequest($e->getMessage()),
);
```

The requester gets a sensible response while the actor recovers for the next attempt. If the request can wait, design the protocol around `ask` returning a typed result (`OK | Rejected`) rather than throwing — the rejection is a normal value; supervision is for the *unexpected* failures.

## Next steps

- [Supervision](../core-concepts/supervision.md) — how `oneForOne`, `allForOne`, and `exponentialBackoff` work under the hood
- [Lifecycle](../core-concepts/lifecycle.md) — `PreStart`, `PostStop`, and the actor state machine that supervision operates on
- [When to use actors](./when-to-use-actors.md) — deciding whether an actor is the right tool for the failure boundary you need
