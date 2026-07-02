---
title: How to rate-limit an actor with a bounded mailbox
related:
  - core-concepts/mailboxes
  - reference/classes/mailbox-config
  - core-concepts/actors
  - guides/fan-out
---

# How to rate-limit an actor with a bounded mailbox

When a high-throughput producer sends messages faster than an actor can process them, the mailbox grows without bound. A bounded mailbox with `OverflowStrategy::Backpressure` applies back-pressure to the sender: the `tell()` call suspends the sending fiber until the mailbox has capacity, naturally throttling the producer.

## Solution

<!-- verify:skip: illustrates mailbox configuration requiring a running actor system -->
```php title="src/Actor/RateLimitedProcessor.php" verify:skip
<?php

declare(strict_types=1);

namespace App\Actor;

use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Runtime\Mailbox\MailboxConfig;
use Monadial\Nexus\Runtime\Mailbox\OverflowStrategy;

$props = Props::fromBehavior(
    Behavior::receive(static function ($ctx, object $msg): Behavior {
        // expensive processing here
        return Behavior::same();
    }),
)->withMailbox(
    MailboxConfig::bounded(capacity: 50, strategy: OverflowStrategy::Backpressure),
);

$ref = $system->spawn($props, 'processor');
```

## How it works

`MailboxConfig::bounded(50, OverflowStrategy::Backpressure)` creates a mailbox that holds at most 50 pending messages. When the mailbox is full and a new `tell()` arrives, the sending fiber suspends until a slot is available. The processor actor empties a slot with each message it handles, waking the earliest suspended sender.

This means the producer's throughput automatically matches the consumer's processing speed — no explicit coordination required.

## Variations

### Token-bucket rate limiter via scheduleRepeatedly

Back-pressure works well when producers are themselves fibers. For producers that cannot be suspended (e.g., inbound HTTP requests or external event sources), use a token-bucket pattern instead: a counter actor that refills a slot count on a fixed interval and drops messages when empty.

<!-- verify:skip: illustrates a stateful token-bucket actor requiring scheduleRepeatedly -->
```php title="src/Actor/TokenBucketActor.php" verify:skip
<?php

declare(strict_types=1);

namespace App\Actor;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\BehaviorWithState;
use Monadial\Nexus\Runtime\Duration;

final readonly class BucketState
{
    public function __construct(
        public int $tokens,
        public int $maxTokens,
    ) {}
}

$tokenBucket = Behavior::setup(static function (ActorContext $ctx): Behavior {
    $maxTokens = 10;

    // Refill 1 token every 100 ms
    $ctx->scheduleRepeatedly(
        Duration::millis(100),
        Duration::millis(100),
        new Refill(),
    );

    return Behavior::withState(
        new BucketState(tokens: $maxTokens, maxTokens: $maxTokens),
        static function (ActorContext $ctx, object $msg, BucketState $state): BehaviorWithState {
            if ($msg instanceof Refill) {
                $newTokens = min($state->tokens + 1, $state->maxTokens);

                return BehaviorWithState::next(new BucketState($newTokens, $state->maxTokens));
            }

            if ($msg instanceof ProcessRequest) {
                if ($state->tokens <= 0) {
                    $msg->replyTo->tell(new RateLimitExceeded());

                    return BehaviorWithState::same();
                }

                // consume a token and process
                doProcess($msg);

                return BehaviorWithState::next(new BucketState($state->tokens - 1, $state->maxTokens));
            }

            return BehaviorWithState::same();
        },
    );
});
```

### Drop newest on overflow

When back-pressure is not acceptable (e.g., telemetry pipelines where dropping is preferable to blocking), use `OverflowStrategy::DropNewest`:

<!-- verify:skip: illustrates drop-newest overflow strategy -->
```php title="src/Actor/TelemetrySink.php" verify:skip
$props = Props::fromBehavior($telemetryBehavior)
    ->withMailbox(MailboxConfig::bounded(1000, OverflowStrategy::DropNewest));
```

Messages arriving when the mailbox is full are silently discarded. The `DeadLetterRef` captures dropped messages if you need visibility.

## Caveats

:::caution Backpressure suspends the sending fiber, not a thread
`OverflowStrategy::Backpressure` works by suspending the PHP fiber that called `tell()`. If the sender is not running inside a fiber (e.g., the main thread), back-pressure cannot suspend it and `MailboxOverflowException` is thrown instead.
:::

- **`ThrowException` is the default.** `MailboxConfig::bounded(N)` without a second argument uses `OverflowStrategy::ThrowException`. Callers must catch `MailboxOverflowException` or let it propagate to the supervisor.
- **Capacity sizing matters.** A capacity of 50 works for actors that process messages in microseconds. For actors with 10–100 ms processing time and burst producers, increase capacity proportionally.
- **Back-pressure propagates upstream.** If actor A back-pressures actor B, and B back-pressures actor C, the whole chain slows to the rate of the slowest consumer. This is the intended behavior, but model your capacity budgets accordingly.

## Related

- [Mailboxes](../core-concepts/mailboxes.md) — how mailboxes queue messages and what overflow strategies do
- [`MailboxConfig`](../reference/classes/mailbox-config.md) — full API reference for bounded and unbounded configs
- [Fan-out](./fan-out.md) — scatter-gather pattern that produces bursts of child messages
