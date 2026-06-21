---
sidebar_position: 8
title: WebSockets
related:
  - http/actors-in-http
  - http/servers
  - http/handlers
  - packages/http-ws
---

# WebSockets

The [`nexus-http-ws`](../packages/http-ws.md) package adds WebSocket routes to the HTTP application. Two flavours of route are available:

- **`ws()`** — per-connection `WebSocketHandler` class. One instance per upgraded connection. Use for echo, per-user state, and command-style protocols.
- **`channel()`** — actor-backed broadcast room. Many connections share one `WebSocketChannelActor`. Use for chat rooms, pub/sub, and collaborative editors. Thread mode only.

## Setup

Switch from `HttpApplication` to `WsApplication` and enable WebSocket on the server config:

```php title="server.php"
use Monadial\Nexus\Http\Server\Swoole\Threads\Server\{SwooleThreadConfig, SwooleThreadServer};
use Monadial\Nexus\Http\Ws\{CompiledApplication, WsApplication};

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

`enableWebSocket(true)` swaps the underlying Swoole server from `Swoole\Http\Server` to `Swoole\WebSocket\Server`, which adds the upgrade handshake support.

## Per-connection handlers

Extend `WebSocketHandler` and override three lifecycle methods:

```php title="src/Http/Handler/EchoHandler.php"
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
```

```php title="server.php"
$app->ws('/ws/echo', EchoHandler::class);
```

One `EchoHandler` instance is created per connection at upgrade time. State on `$this` is per-connection — no locking needed.

### Lifecycle

`onOpen()` and `onClose()` fire exactly once per connection. `onMessage()` fires zero or more times. Throwing from any of them closes the connection with `1011` (server error) and logs the exception.

## `WebSocketContext`

Per-connection handle. Inject via `#[FromContext]`:

```php title="src/Http/Handler/ChatHandler.php"
$ctx->id();                            // int — connection fd, unique within this thread
$ctx->request();                       // ServerRequestInterface — the original upgrade request
$ctx->send($text);                     // send a TEXT frame
$ctx->sendBinary($data);               // send a BINARY frame
$ctx->sendPing();                      // send a control PING
$ctx->close($code, $reason);          // close with WebSocket close code
$ctx->isAlive();                       // bool — still connected
```

WebSocket routes accept path parameters identical to HTTP routes:

```php title="src/Http/Handler/ChatHandler.php"
$app->ws('/ws/chat/{room}', ChatHandler::class);

// Inside onOpen():
$room = (string) $this->ctx->request()->getAttribute('room');
```

## `WebSocketFrame`

Immutable frame value object:

```php title="src/Http/Handler/BinaryHandler.php"
public function onMessage(WebSocketFrame $frame): void
{
    if ($frame->kind === 2) {          // 1 = TEXT, 2 = BINARY
        $this->processBinary($frame->text);
        return;
    }

    $this->processText($frame->text);
}
```

## Channel-backed routes (broadcast)

For broadcast and fan-out scenarios — chat rooms, presence, live updates — route to an actor instead of a per-connection handler:

```php title="src/Http/Actor/ChatRoomActor.php"
use Monadial\Nexus\Http\Ws\WebSocket\{WebSocketChannelActor, WebSocketContext, WebSocketFrame};

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
```

```php title="server.php"
$app->channel('/ws/chat/{room}', ChatRoomActor::class, key: 'room');
```

The `key` parameter selects which path attribute partitions the actor — each `{room}` value gets its own actor instance with its own connection set. `$this->broadcast()` sends to every open connection in that partition, across all threads.

:::caution Thread mode only
`channel()` routes require a shared `Swoole\Thread\Map` for the channel registry. Worker-mode Swoole has no equivalent shared memory store, so `channel()` routes throw at compile time. Use plain `ws()` routes with per-connection handlers in worker mode.
:::

For targeted sends to one user:

```php title="src/Http/Actor/ChatRoomActor.php"
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

## Dependency injection in WebSocket handlers

The same attributes used for HTTP handlers work here:

```php title="src/Http/Handler/ChatHandler.php"
final class ChatHandler extends WebSocketHandler
{
    public function __construct(
        #[FromContext] private readonly WebSocketContext $ctx,
        #[FromActor('chat-room')] private readonly ActorRef $room,
        #[FromService(LoggerInterface::class)] private readonly LoggerInterface $log,
    ) {}
}
```

`#[FromContext]` is WebSocket-specific. `#[FromActor]` and `#[FromService]` behave identically to HTTP. `#[FromBody]` does not apply — WebSocket frames arrive on `onMessage()` as `WebSocketFrame` objects.

## Close codes

Use standard WebSocket close codes with `$ctx->close($code)`:

| Code | Meaning |
|---|---|
| `1000` | Normal closure |
| `1001` | Going away (server shutdown) |
| `1002` | Protocol error |
| `1003` | Unsupported data |
| `1008` | Policy violation (auth fail, bad room) |
| `1009` | Message too large |
| `1011` | Internal server error |
| `4000+` | Application-defined |

## Sending from outside `onMessage()`

A handler can hold its `WebSocketContext` and call `send()` from outside `onMessage()` — for instance, in response to an external event delivered by an actor:

```php title="src/Http/Handler/NotificationHandler.php"
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

Always check `isAlive()` before sending — the client may have disconnected between events.

## See also

- [Actors in HTTP](./actors-in-http.md) — the actor integration patterns behind channel routes.
- [Servers](./servers.md) — thread mode prerequisites for `channel()` routes.
- [Auth](./auth.md) — protecting WebSocket upgrades with `#[RequiresAuth]`.
