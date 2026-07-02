---
title: How to scatter-gather across child actors
related:
  - core-concepts/supervision
  - core-concepts/lifecycle
  - core-concepts/actors
  - guides/routing-patterns
---

# How to scatter-gather across child actors

When a parent actor needs to fan out work to N children, collect all their replies, and produce a single result, the scatter-gather pattern applies. The parent spawns children, sends each a request, and waits until all have responded before proceeding.

## Solution

<!-- verify:skip: illustrates a multi-actor scatter-gather requiring a running actor system -->
```php title="src/Actor/AggregatorActor.php" verify:skip
<?php

declare(strict_types=1);

namespace App\Actor;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\BehaviorWithState;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Lifecycle\Terminated;

final readonly class AggregateState
{
    public function __construct(
        public int $pending,
        public array $results,
    ) {}
}

$aggregatorBehavior = Behavior::setup(static function (ActorContext $ctx): Behavior {
    $sources = ['worker-a', 'worker-b', 'worker-c'];

    foreach ($sources as $name) {
        $child = $ctx->spawn(Props::fromBehavior(workerBehavior()), $name);
        $child->tell(new FetchData($ctx->self()));
        $ctx->watch($child);
    }

    return Behavior::withState(
        new AggregateState(pending: count($sources), results: []),
        static function (ActorContext $ctx, object $msg, AggregateState $state): BehaviorWithState {
            if ($msg instanceof DataResult) {
                $results = [...$state->results, $msg->value];
                $pending = $state->pending - 1;

                if ($pending === 0) {
                    $ctx->parent()?->tell(new AggregateComplete($results));

                    return BehaviorWithState::stopped();
                }

                return BehaviorWithState::next(new AggregateState($pending, $results));
            }

            return BehaviorWithState::same();
        },
    );
});
```

## How it works

`Behavior::setup()` runs once when the actor starts, before any messages arrive. Inside setup, the parent spawns N children, sends each an initial request, and registers a death watch with `$ctx->watch($child)`. The actor then switches to a stateful behavior that counts down `$pending` as each `DataResult` arrives. When `$pending` reaches zero, the parent notifies its own parent and stops itself.

`$ctx->watch()` registers the current actor to receive a `Terminated` signal when any watched child stops. This provides a safety net: if a child stops without sending a reply (due to an exception or `PoisonPill`), the parent's `Terminated` handler can decrement the counter and avoid waiting forever.

## Variations

### Handling partial failures with Terminated

If a child crashes and is not restarted, the parent receives `Terminated` instead of the expected reply. Add a `Terminated` handler to the signal chain to avoid hanging indefinitely:

<!-- verify:skip: extends the aggregator pattern above with signal handling -->
```php title="src/Actor/FaultTolerantAggregator.php" verify:skip
$behavior = $behavior->onSignal(
    static function (ActorContext $ctx, object $signal) use (&$state): Behavior {
        if ($signal instanceof Terminated) {
            // Child stopped without replying — count it as a failure
            $state = new AggregateState(
                pending: $state->pending - 1,
                results: $state->results,
            );

            if ($state->pending === 0) {
                $ctx->parent()?->tell(new AggregateComplete($state->results));

                return Behavior::stopped();
            }
        }

        return Behavior::same();
    },
);
```

### Counter actor pattern

For larger fan-outs, extract the counting logic into a dedicated counter actor that the parent sends results to. This keeps the aggregator behavior stateless and makes it easier to add timeout logic via `$ctx->scheduleOnce()`.

## Caveats

:::caution Watch registration must happen before the child can stop
Call `$ctx->watch($child)` immediately after `$ctx->spawn()`. If a child stops before `watch()` is called, the `Terminated` signal is delivered at registration time — but if `watch()` is never called, the parent never learns the child stopped.
:::

- **Unbounded fan-out creates unbounded memory pressure.** If N is large and replies are slow, messages accumulate in the parent's mailbox. Consider batching or using a bounded mailbox with `MailboxConfig::bounded()`.
- **Dead children still send Terminated.** If you `watch()` an actor that is already stopped, `Terminated` is delivered immediately. This is correct behavior, not a bug.
- **`ask()` is an alternative for small fan-outs.** For 2–5 children, `$ref->ask(...)` per child and `Future::all([...])` is simpler than a full scatter-gather actor. Use the actor pattern when you need supervision, state accumulation, or timeout control.

## Related

- [Actor lifecycle](../core-concepts/lifecycle.md) — when `PreStart`, `PostStop`, and `Terminated` fire
- [Actor context](../reference/classes/actor-context.md) — `watch()`, `spawn()`, and `scheduleOnce()` signatures
- [Supervision](../core-concepts/supervision.md) — configuring restart strategies for child actors
