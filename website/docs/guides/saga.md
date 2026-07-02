---
title: How to implement a saga (process manager)
related:
  - persistence/overview
  - reference/classes/event-sourced-behavior
  - reference/classes/effect
  - guides/single-writer-aggregates
---

# How to implement a saga (process manager)

A saga coordinates a long-running workflow that spans multiple aggregates. When a step succeeds, the saga sends a command to the next aggregate; when a step fails, it issues compensating commands to roll back completed steps. `EventSourcedBehavior` is the right foundation: the saga's progress is durably persisted, so it survives crashes and replays exactly where it left off.

## Solution

<!-- verify:skip: illustrates a multi-aggregate saga requiring a running actor system with persistence -->
```php title="src/Saga/OrderFulfillmentSaga.php" verify:skip
<?php

declare(strict_types=1);

namespace App\Saga;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
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

function orderFulfillmentSaga(
    string $orderId,
    ActorRef $paymentActor,
    ActorRef $inventoryActor,
): Behavior {
    return EventSourcedBehavior::create(
        PersistenceId::of('Saga', "fulfillment-{$orderId}"),
        new FulfillmentState(),
        static function (ActorContext $ctx, object $cmd, FulfillmentState $state): Effect {
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

The saga actor receives domain events from other aggregates (passed as commands via `tell()`). Each event is persisted with `Effect::persist()` before any side-effect runs — this is the key property: if the actor crashes between receiving `PaymentCharged` and sending `ReserveStock`, it will replay the persisted event on restart and re-issue the command. The side-effect in `thenRun()` is idempotent from the aggregate's perspective because inventory reservations should check for duplicates.

The event handler folds each event onto `FulfillmentState`. The saga can reconstruct its exact progress from the event log on any restart, with no external coordination.

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
- **`Effect::persist()` is synchronous within the handler.** Events are written to the store before `thenRun()` fires. If the event store is unavailable, the command handler throws and the supervisor handles it.

## Related

- [Event-sourced behavior](../reference/classes/event-sourced-behavior.md) — full API for `EventSourcedBehavior` and `Effect`
- [Single-writer aggregates](./single-writer-aggregates.md) — why one actor owns one aggregate
- [Persistence overview](../persistence/overview.md) — event stores, snapshot stores, and recovery
