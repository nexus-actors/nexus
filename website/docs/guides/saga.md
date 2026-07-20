---
title: How to implement a saga (process manager)
related:
  - persistence/overview
  - reference/classes/event-sourced-behavior
  - reference/classes/effect
  - guides/single-writer-aggregates
---

# How to implement a saga (process manager)

:::warning Experimental
The persistence layer is experimental and pre-1.0. APIs and storage formats may change in breaking ways between releases.
:::

A saga coordinates a long-running workflow that spans multiple aggregates. When a step succeeds, the saga sends a command to the next aggregate; when a step fails, it issues compensating commands to roll back completed steps. `EventSourcedBehavior` gives the saga durable memory: recorded progress survives crashes, and a restarted saga recovers its state from the event log. It does not replay in-flight side-effects — outgoing commands sent from `thenRun()` hooks are not reissued on recovery (see Caveats).

## Solution

<!-- verify:skip: illustrates a multi-aggregate saga requiring a running actor system with persistence -->
```php title="src/Saga/OrderFulfillmentSaga.php" verify:skip
<?php

declare(strict_types=1);

namespace App\Saga;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Persistence\Event\EventStore;
use Monadial\Nexus\Persistence\EventSourced\Effect;
use Monadial\Nexus\Persistence\EventSourced\EventSourcedBehavior;
use Monadial\Nexus\Persistence\PersistenceId;

// Domain events the saga reacts to
readonly class OrderPlaced    { public function __construct(public string $orderId, public string $customerId) {} }
readonly class PaymentCharged { public function __construct(public string $orderId) {} }
readonly class PaymentFailed  { public function __construct(public string $orderId) {} }
readonly class StockReserved  { public function __construct(public string $orderId) {} }

// Saga state
readonly class FulfillmentState
{
    public function __construct(
        public bool $paymentComplete = false,
        public bool $stockReserved = false,
        public bool $cancelled = false,
    ) {}
}

/**
 * @param ActorRef<ChargePayment> $paymentActor
 * @param ActorRef<ReserveStock|CancelReservation> $inventoryActor
 */
function orderFulfillmentSaga(
    string $orderId,
    ActorRef $paymentActor,
    ActorRef $inventoryActor,
    EventStore $eventStore,
): Behavior {
    return EventSourcedBehavior::create(
        PersistenceId::of('Saga', "fulfillment-{$orderId}"),
        new FulfillmentState(),
        static function (FulfillmentState $state, ActorContext $ctx, object $cmd) use ($paymentActor, $inventoryActor): Effect {
            return match (true) {
                $cmd instanceof OrderPlaced => Effect::persist($cmd)
                    ->thenRun(static fn() => $paymentActor->tell(new ChargePayment($cmd->orderId))),

                $cmd instanceof PaymentCharged => Effect::persist($cmd)
                    ->thenRun(static fn() => $inventoryActor->tell(new ReserveStock($cmd->orderId))),

                $cmd instanceof PaymentFailed => Effect::persist($cmd)
                    ->thenRun(static fn() => $inventoryActor->tell(new CancelReservation($cmd->orderId))),

                $cmd instanceof StockReserved => Effect::persist($cmd)
                    ->thenRun(static fn(FulfillmentState $s) =>
                        $s->paymentComplete && $s->stockReserved
                            ? $ctx->self()->tell(new CompleteFulfillment($cmd->orderId))
                            : null
                    ),

                default => Effect::none(),
            };
        },
        static function (FulfillmentState $state, object $event): FulfillmentState {
            return match (true) {
                $event instanceof PaymentCharged => new FulfillmentState(paymentComplete: true, stockReserved: $state->stockReserved),
                $event instanceof StockReserved  => new FulfillmentState(paymentComplete: $state->paymentComplete, stockReserved: true),
                $event instanceof PaymentFailed  => new FulfillmentState(cancelled: true),
                default => $state,
            };
        },
    )
        ->withEventStore($eventStore)
        ->toBehavior();
}
```

## How it works

The saga actor receives domain events from other aggregates (passed as commands via `tell()`). Each event is persisted with `Effect::persist()` before any side-effect runs, so the event log is a durable record of what the saga has seen. The side-effects themselves are at-most-once: a `thenRun()` hook fires once, immediately after the write, and is never re-executed. If the actor crashes between persisting `PaymentCharged` and sending `ReserveStock`, recovery rebuilds the state from the log but does not re-run the hook or reissue the command — the saga stalls at that step. Nexus provides no durable-saga guarantee out of the box; a stalled saga must be nudged forward externally, for example by a timeout command (see below) or by the upstream aggregate redelivering its event. Because redelivery can also cause duplicates, saga commands to aggregates should be idempotent.

The event handler folds each event onto `FulfillmentState`. The saga can reconstruct which events it has recorded from the event log on any restart — but not which outgoing commands were actually sent.

## Variations

### Compensating transactions on failure

When `PaymentFailed` arrives, the saga issues a `CancelReservation` command. If stock was already reserved at that point, the inventory actor receives the cancellation. If stock was not yet reserved, the cancellation is a no-op. Each compensating command is also persisted, creating a complete audit trail of what happened and what was reversed.

### Timeout via scheduleOnce

Sagas that must complete within a deadline can use `scheduleOnce()` to send a `FulfillmentTimeout` command:

<!-- verify:skip: illustrates scheduleOnce in a saga setup context -->
```php title="src/Saga/OrderFulfillmentSaga.php" verify:skip
$behavior = Behavior::setup(static function (ActorContext $ctx) use ($sagaBehavior, $orderId): Behavior {
    $ctx->scheduleOnce(Duration::seconds(300), new FulfillmentTimeout($orderId));

    return $sagaBehavior;
});
```

The timeout command is processed like any other — persisted and applied via the event handler.

## Caveats

:::caution Sagas must not call aggregates synchronously
Use `tell()`, not `ask()`, when the saga sends commands to aggregates. An `ask()` inside a persistence command handler will deadlock: the command handler is running inside the actor's fiber, which cannot suspend to wait for a reply while also processing the effect.
:::

- **Each domain event source must know the saga's actor ref.** The common pattern is to spawn the saga actor first, then pass its ref as the `replyTo` on initial commands to the source aggregates. The aggregates then `tell()` the saga directly.
- **Exactly-once delivery is not guaranteed.** If an aggregate restarts between receiving a command and persisting its event, it may process the command twice. Saga commands to aggregates should be idempotent (use `$idempotencyKey` fields).
- **Side-effects are at-most-once.** `thenRun()` hooks are not re-executed during recovery — a crash between the persist and the hook silently loses the outgoing command. Design for external redelivery (timeouts, upstream retries) rather than assuming the saga resumes by itself.
- **`Effect::persist()` is synchronous within the handler.** Events are written to the store before `thenRun()` fires. If the event store is unavailable, the command handler throws and the supervisor handles it.

## Related

- [Event-sourced behavior](../reference/classes/event-sourced-behavior.md) — full API for `EventSourcedBehavior` and `Effect`
- [Single-writer aggregates](./single-writer-aggregates.md) — why one actor owns one aggregate
- [Persistence overview](../persistence/overview.md) — event stores, snapshot stores, and recovery
