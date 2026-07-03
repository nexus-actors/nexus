---
title: MessengerActorRef
sidebar_position: 26
related:
  - packages/messenger
  - reference/classes/actor-ref
---

# MessengerActorRef

Location-transparent `ActorRef` backed by a Symfony Messenger `SenderInterface`; `tell()` publishes to a broker transport while remaining byte-identical to a local actor send.

## What it does

`MessengerActorRef<T>` implements `ActorRef<T>` so actor code that already knows how to `tell()` a local ref can publish to AMQP, Redis, or any other Messenger transport without changes. The ref is fully decoupled from Symfony Framework and `symfony/console`.

In v1, `ask()` is not supported — broker request/reply requires correlation stamps and a reply transport. Calling `ask()` throws `UnsupportedOperationException` immediately.

**Observability:** when an `Observability` instance is provided (the fourth constructor parameter), each `tell()` is wrapped in a `messenger.send` Producer span and the `nexus.messenger.messages.sent` counter is incremented. Telemetry errors are swallowed so they never interrupt the send.

**PSR-14 events:** when an `EventDispatcherInterface` is provided (the fifth parameter), a `MessagePublished` event is dispatched after every successful send.

**Trace context:** when observability is enabled, `tell()` injects a `TraceContextStamp` onto the envelope before forwarding it to the sender. The consumer can extract this stamp to open a child span that continues the originating trace.

The idiomatic way to obtain an instance is `MessengerBridge::producer()`, which accepts the same parameters and returns a correctly typed ref.

## Constructor

```php
use Monadial\Nexus\Messenger\Producer\MessengerActorRef;
use Monadial\Nexus\Observability\Observability;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

new MessengerActorRef(
    sender: SenderInterface $sender,
    senderName: string $senderName,
    sourcePath: ?ActorPath $sourcePath = null,
    observability: Observability $observability = new NoopObservability(),
    events: ?EventDispatcherInterface $events = null,
);
```

| Parameter | Type | Default | Description |
|---|---|---|---|
| `$sender` | `SenderInterface` | — | Messenger transport or bus sender to publish to. |
| `$senderName` | `string` | — | Logical name; used in the synthetic actor path `/messenger/<name>` and in span/event attributes. |
| `$sourcePath` | `?ActorPath` | `null` | When set, attaches a `SourceActorPathStamp` to every outbound envelope for provenance tracking. |
| `$observability` | `Observability` | `NoopObservability` | OTel instrumentation. Pass the `Observability` from the actor system to enable spans and counters. |
| `$events` | `?EventDispatcherInterface` | `null` | PSR-14 dispatcher. When set, a `MessagePublished` event is dispatched after each successful send. |

## Methods

| Method | Returns | Description |
|---|---|---|
| `tell(object $message): void` | `void` | Publish a message to the transport. When observability is enabled, emits a `messenger.send` Producer span and increments `nexus.messenger.messages.sent`. Dispatches `MessagePublished` if an event dispatcher is configured. |
| `ask(object $message, Duration $timeout): Future` | never | Always throws `UnsupportedOperationException`. Broker request/reply is deferred beyond v1. |
| `path(): ActorPath` | `ActorPath` | Returns the synthetic path `/messenger/<senderName>`. Not resolvable from the actor system. |
| `isAlive(): bool` | `bool` | Always returns `true` — liveness is delegated to the transport layer. |

## Example

```php title="src/bootstrap.php"
use Monadial\Nexus\Messenger\MessengerBridge;

// Preferred: use the bridge factory
$orders = MessengerBridge::producer($transport, 'orders-out');
$orders->tell(new OrderPlaced('A-42'));

// With observability and PSR-14 events
$orders = MessengerBridge::producer(
    sender: $transport,
    name: 'orders-out',
    sourcePath: null,
    observability: $observability,
    events: $eventDispatcher,
);
$orders->tell(new OrderPlaced('A-42'));
// → emits messenger.send span
// → increments nexus.messenger.messages.sent{nexus.message.type="OrderPlaced"}
// → dispatches MessagePublished($message, 'orders-out')
```

Messages sent via `tell()` must carry `#[MessageType]` (enforced by the nexus-psalm plugin when sending to a `MessengerActorRef`).

## Full API reference

[Full class and method signatures](https://api.nexusactors.com/classes/Monadial-Nexus-Messenger-Producer-MessengerActorRef.html)

## See also

- [nexus-messenger package](../../packages/messenger) — bridge overview, install, and full wiring guide
- [ActorRef](actor-ref) — the interface this class implements
- [Messenger bridge guide](../../guides/messenger-bridge) — end-to-end wiring walkthrough
- [Exceptions — UnsupportedOperationException](../exceptions.md#unsupportedoperationexception) — thrown by `ask()` in v1
- [Attributes — #[MessageType]](../attributes.md#messagetype) — required on messages sent via this ref
