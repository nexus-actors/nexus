---
title: ReceiverActor
sidebar_position: 27
related:
  - packages/messenger
  - reference/classes/actor-system
---

# ReceiverActor

Supervised poll → route → ack loop over one Symfony Messenger `ReceiverInterface`; delivers broker messages to Nexus actor mailboxes with at-least-once semantics.

## What it does

`ReceiverActor` is a behavior factory — it returns a `Behavior<object>` ready to pass to `Props::fromBehavior()` or `MessengerBridge::receiverProps()`. Spawn one actor per Messenger receiver (transport). The actor self-schedules `Poll` ticks and drives the receive loop independently of `symfony/console`.

**Poll semantics:** each tick calls `ReceiverInterface::get()` and routes every envelope through the configured `MessageRouter`. An accepted envelope is acked immediately; a `Backpressured` or `Dropped` mailbox result stops the tick without acking so the broker redelivers (at-least-once). After a busy tick the next poll fires immediately; after an idle or backpressured tick the next poll is scheduled after `pollInterval` (default 100 ms).

**Unroutable messages:** when `route()` returns `null`, the policy in `ReceiverActorConfig` decides the outcome — `Reject` (default) rejects the envelope back to the transport; `DeadLetters` forwards the inner message to the configured `$deadLetters` ref and acks.

**Observability:** every drained envelope is traced with a `messenger.receive` Consumer span. The `nexus.messenger.outcome` span attribute records the result for each envelope:

| Value | Meaning |
|---|---|
| `acked` | Message delivered to actor mailbox and acked to the transport. |
| `backpressured` | Target mailbox at capacity (non-accepted result); tick paused without acking. |
| `dropped` | Target mailbox returned `Dropped`; tick paused without acking. |
| `rejected` | Unroutable message rejected back to the transport. |
| `dead_lettered` | Unroutable message forwarded to the dead-letters ref and acked. |

Consumer counters incremented per outcome:

| Counter | Unit | When |
|---|---|---|
| `nexus.messenger.messages.consumed` | `{message}` | Envelope acked after successful delivery. |
| `nexus.messenger.enqueue.backpressured` | `{message}` | Mailbox returned non-accepted. |
| `nexus.messenger.messages.rejected` | `{message}` | Unroutable + `UnroutablePolicy::Reject`. |
| `nexus.messenger.messages.dead_lettered` | `{message}` | Unroutable + `UnroutablePolicy::DeadLetters`. |

**PSR-14 events:** when an `EventDispatcherInterface` is provided, the following events are dispatched per outcome:

| Event class | When |
|---|---|
| `MessageConsumed($message, $targetPath)` | Envelope acked after delivery. |
| `MessageRejected($message)` | Unroutable message rejected. |
| `MessageDeadLettered($message)` | Unroutable message forwarded to dead letters. |

**Trace context:** if the envelope carries a `TraceContextStamp` and observability is provided, the consumer extracts the carrier and opens the `messenger.receive` span as a child of the originating trace.

**LifecycleWatchdog integration:** if a `$processedListener` ref is provided, the actor sends a `MessagesProcessed($count)` report to it after each tick that processed at least one message. `LifecycleWatchdog` uses this to count messages toward its limits.

## Factory

```php
use Monadial\Nexus\Messenger\Consumer\ReceiverActor;

ReceiverActor::create(
    receiver: ReceiverInterface $receiver,
    router: MessageRouter $router,
    config: ?ReceiverActorConfig $config = null,
    deadLetters: ?ActorRef $deadLetters = null,
    processedListener: ?ActorRef $processedListener = null,
    events: ?EventDispatcherInterface $events = null,
    observability: ?Observability $observability = null,
): Behavior
```

| Parameter | Type | Default | Description |
|---|---|---|---|
| `$receiver` | `ReceiverInterface` | — | Messenger transport receiver to poll. |
| `$router` | `MessageRouter` | — | Resolves each envelope to a target `ActorRef`. |
| `$config` | `?ReceiverActorConfig` | `ReceiverActorConfig::default()` | Poll interval and unroutable policy. |
| `$deadLetters` | `?ActorRef` | `null` | Required when `unroutablePolicy` is `DeadLetters`. |
| `$processedListener` | `?ActorRef` | `null` | Receives `MessagesProcessed` reports; wire to a `LifecycleWatchdog`. |
| `$events` | `?EventDispatcherInterface` | `null` | PSR-14 dispatcher for consume/reject/dead-letter events. |
| `$observability` | `?Observability` | `null` | OTel instrumentation for spans, counters, and trace-context extraction. |

## Example

```php title="src/bootstrap.php"
use Monadial\Nexus\Messenger\Consumer\ReceiverActorConfig;
use Monadial\Nexus\Messenger\Consumer\UnroutablePolicy;
use Monadial\Nexus\Messenger\MessengerBridge;
use Monadial\Nexus\Messenger\Routing\MapMessageRouter;
use Monadial\Nexus\Runtime\Duration;

$router = new MapMessageRouter([OrderPlaced::class => $ordersActor]);

// Minimal
$system->spawn(
    MessengerBridge::receiverProps($transport, $router),
    'orders-receiver',
);

// Fully wired
$config = ReceiverActorConfig::default()
    ->withPollInterval(Duration::millis(50))
    ->withUnroutablePolicy(UnroutablePolicy::DeadLetters);

$system->spawn(
    MessengerBridge::receiverProps(
        receiver: $transport,
        router: $router,
        config: $config,
        deadLetters: $system->deadLetters(),
        processedListener: $watchdogRef,
        events: $eventDispatcher,
        observability: $observability,
    ),
    'orders-receiver',
);
```

## Full API reference

[Full class and method signatures](https://api.nexusactors.com/classes/Monadial-Nexus-Messenger-Consumer-ReceiverActor.html)

## See also

- [nexus-messenger package](../../packages/messenger) — bridge overview, install, and full wiring guide
- [ActorSystem](actor-system) — `$system->spawn()` and `$system->deadLetters()`
- [Messenger bridge guide](../../guides/messenger-bridge) — end-to-end wiring walkthrough
- [Config — ReceiverActorConfig](../config.md#receiveractorconfig) — poll interval and unroutable policy
- [MessageRouter](message-router) — the routing interface used by this actor
- [LifecycleWatchdog](lifecycle-watchdog) — consumer for `MessagesProcessed` reports
