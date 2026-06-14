---
sidebar_position: 8
title: WebSockets
---

# WebSockets

Nexus adds WebSocket routes to the HTTP application via the
[`nexus-http-ws`](../packages/http-ws.md) package. Two flavours of route:

- **`ws()`** — per-connection `WebSocketHandler` class. One instance per
  upgraded connection. Use for echo, per-user state, command-style
  protocols.
- **`channel()`** — actor-backed broadcast room. Many connections share
  one `WebSocketChannelActor`. Use for chat rooms, pub/sub, collaborative
  editors. **Thread mode only.**

The same `WsApplication` builder declares both.

## Setup

Switch from `HttpApplication` to `WsApplication`, enable WebSocket on the
server config:

```php
SwooleThreadServer::run(
    SwooleThreadConfig::bind('0.0.0.0', 8080)
        ->threads(4)
        ->enableWebSocket(true),
    static function (ActorSystem $system, WorkerNode $node): CompiledApplication {
        return WsApplication::create($system)
            ->get('/', static fn() => Response::ok())
            ->ws('/ws/echo', EchoHandler::class)
            ->compile();
    },
);
```

`enableWebSocket(true)` swaps the underlying Swoole server from
`Swoole\Http\Server` to `Swoole\WebSocket\Server`, which adds the upgrade
handshake support.

## Per-Connection Handlers

Extend `WebSocketHandler` and override three lifecycle methods:

```php
use Monadial\Nexus\Http\Ws\WebSocket\Attribute\FromContext;
use Monadial\Nexus\Http\Ws\WebSocket\{WebSocketContext, WebSocketFrame, WebSocketHandler};

final class EchoHandler extends WebSocketHandler
{
    public function __construct(
        #[FromContext] private readonly WebSocketContext $ctx,
    ) {}

    #[\Override]
    public function onOpen(): void
    {
        $this->ctx->send('welcome');
    }

    #[\Override]
    public function onMessage(WebSocketFrame $frame): void
    {
        $this->ctx->send('echo:' . $frame->text);
    }

    #[\Override]
    public function onClose(int $code): void
    {
        // cleanup
    }
}

$app->ws('/ws/echo', EchoHandler::class);
```

One `EchoHandler` instance is created per connection at upgrade time.
State on `$this` is per-connection — no locking needed.

### Lifecycle

```
upgrade request    → WsApplication::ws() route matched
                  → handler constructed (#[FromContext] / #[FromActor] / #[FromService] resolved)
                  → onOpen() called
        ┌──────────────────────────────────────────────┐
        ▼                                              │ frame arrives
  onMessage(frame)  ←─ loop ──┐                        │
        ▼                     │                        │
  $ctx->send(...) optional ───┘                        │
        ▼                                              │
  client / server disconnect → onClose($code)
```

`onOpen()` and `onClose()` always fire exactly once per connection.
`onMessage()` fires zero or more times. Throwing from any of them closes
the connection with `1011` (server error) and is logged.

## WebSocketContext

Per-connection handle. Inject via `#[FromContext]`:

```php
$ctx->id();           // int — connection fd, unique within this thread
$ctx->request();      // ServerRequestInterface — the original upgrade request
$ctx->send($text);    // send a TEXT frame
$ctx->sendBinary($data); // send a BINARY frame
$ctx->sendPing();     // send a control PING (keep-alive)
$ctx->close($code, $reason);   // close with WebSocket close code
$ctx->isAlive();      // bool — still connected
```

### Path Parameters

WebSocket routes accept path parameters identical to HTTP routes:

```php
$app->ws('/ws/chat/{room}', ChatHandler::class);

final class ChatHandler extends WebSocketHandler
{
    public function __construct(
        #[FromContext] private readonly WebSocketContext $ctx,
    ) {}

    #[\Override]
    public function onOpen(): void
    {
        $room = (string) $this->ctx->request()->getAttribute('room');
        $this->ctx->send("joined room {$room}");
    }
}
```

The upgrade request is preserved on the context, so query strings,
headers, and routed attributes are all available.

## WebSocketFrame

Immutable frame value object:

```php
final readonly class WebSocketFrame
{
    public function __construct(
        public int $kind,    // 1 = TEXT, 2 = BINARY
        public string $text, // raw payload bytes
    ) {}
}
```

Branch on `$frame->kind` if you handle both:

```php
public function onMessage(WebSocketFrame $frame): void
{
    if ($frame->kind === 2) {
        $this->processBinary($frame->text);
        return;
    }

    $this->processText($frame->text);
}
```

## Channel-Backed Routes (Broadcast)

For broadcast / fan-out scenarios — chat rooms, presence, live updates —
route to an actor instead of a per-connection handler:

```php
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketChannelActor;

final class ChatRoomActor extends WebSocketChannelActor
{
    #[\Override]
    public function onOpen(WebSocketContext $ctx): void
    {
        $this->broadcast("user {$ctx->id()} joined");
    }

    #[\Override]
    public function onMessage(WebSocketContext $ctx, WebSocketFrame $frame): void
    {
        $this->broadcast("{$ctx->id()}: {$frame->text}");
    }

    #[\Override]
    public function onClose(WebSocketContext $ctx, int $code): void
    {
        $this->broadcast("user {$ctx->id()} left");
    }
}

$app->channel('/ws/chat/{room}', ChatRoomActor::class, key: 'room');
```

The `key` parameter selects which path attribute partitions the actor:
each `{room}` value gets its own actor instance with its own connection
set. Calling `$this->broadcast()` from inside the actor sends the message
to every open connection in that partition — across all threads.

### Why Channel Actors Are Thread-Mode Only

Connection ownership is per-thread, but the actor's state needs to outlive
any one thread (so connections on thread 3 can broadcast through an
actor whose mailbox lives on thread 7). The channel registry is backed by
a shared `Swoole\Thread\Map`. Worker-mode Swoole has no equivalent shared
memory store, so `channel()` routes throw at compile time.

For broadcast without thread mode, you can build a similar pattern by hand
using a message broker (Redis pub/sub, NATS, …). The actor model just
makes the in-process case trivial.

### `broadcast()` Semantics

`$this->broadcast($message)` enqueues the send on every connection in the
partition. Connections that have died since the actor last saw them are
silently skipped (the connection table reaps them on `onClose`).

For targeted sends (one user, not everyone):

```php
public function onMessage(WebSocketContext $ctx, WebSocketFrame $frame): void
{
    if (str_starts_with($frame->text, '/whisper ')) {
        [$target, $msg] = $this->parseWhisper($frame->text);
        $this->sendTo($target, "[private] {$msg}");
        return;
    }

    $this->broadcast($frame->text);
}
```

## Dependency Injection in WebSocket Handlers

The same attributes used for HTTP handlers work here:

```php
final class ChatHandler extends WebSocketHandler
{
    public function __construct(
        #[FromContext] private readonly WebSocketContext $ctx,
        #[FromActor('chat-room')] private readonly ActorRef $room,
        #[FromService(LoggerInterface::class)] private readonly LoggerInterface $log,
    ) {}
}
```

`#[FromContext]` is WebSocket-specific; `#[FromActor]` and `#[FromService]`
behave identically to HTTP. `#[FromBody]` doesn't apply — WebSocket frames
arrive on `onMessage()` as `WebSocketFrame` objects.

## Close Codes

Use standard WebSocket close codes for `$ctx->close($code)`:

| Code | Meaning |
|---|---|
| `1000` | Normal closure |
| `1001` | Going away (server shutdown) |
| `1002` | Protocol error |
| `1003` | Unsupported data |
| `1008` | Policy violation (auth fail, bad room, …) |
| `1009` | Message too large |
| `1011` | Internal server error |
| `4000+` | Application-defined |

`onClose($code)` receives the same code on the receiving side, regardless
of which peer initiated the close.

## Sending From Anywhere

A handler can hold its `WebSocketContext` and call `send()` from outside
`onMessage()` — for instance, in response to an external event:

```php
final class NotificationHandler extends WebSocketHandler
{
    public function __construct(
        #[FromContext] private readonly WebSocketContext $ctx,
        #[FromActor('notifications')] private readonly ActorRef $hub,
    ) {}

    #[\Override]
    public function onOpen(): void
    {
        $this->hub->tell(new Subscribe($this->ctx->id(), $this));
    }

    public function notify(string $message): void
    {
        if ($this->ctx->isAlive()) {
            $this->ctx->send($message);
        }
    }
}
```

The actor invokes `$handler->notify()` (via a separate channel) when
there's something to push. Always check `isAlive()` before sending — the
client may have disconnected between events.

## Composition

```
WsApplication
  ├── ->ws($path, HandlerClass)        → per-connection
  └── ->channel($path, ActorClass, key) → actor-backed broadcast
                    │
                    ▼
            CompiledWsApplication
                    │
                    ▼
          SwooleThreadServer / SwooleWorkerServer
                    │
                    ▼
          Swoole\WebSocket\Server
                    │
            ┌───────┴───────┐
            ▼               ▼
       onUpgrade        onMessage
            │               │
            ▼               ▼
     handler->onOpen   handler->onMessage(frame)
                            │
                            ▼
                     handler->onClose($code)
```

Next: [Actors in HTTP](./actors-in-http.md) for the bridging pattern, or
back to [Servers](./servers.md) to pick the right runner.
