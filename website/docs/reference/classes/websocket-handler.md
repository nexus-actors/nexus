---
title: WebSocketHandler
sidebar_position: 20
related:
  - http/websockets
  - reference/classes/actor-context
---

# WebSocketHandler

Base class for WebSocket connection handlers; extend to define per-connection behaviour.

## What it does

`WebSocketHandler` is an abstract class you extend to handle WebSocket connections. One
instance is created per WebSocket connection; the framework resolves it through the
PSR-11 container so constructor parameters participate in dependency injection.

Two PHP attributes enable special injection:

- `#[FromContext]` — injects the live `WebSocketContext` for the current connection,
  giving access to `send()`, `close()`, and connection metadata (request, IP, headers).
- `#[FromActor('name')]` — injects an `ActorRef` to a named actor registered with the
  parent `WsApplication`, enabling the handler to talk to the actor system.

The framework calls three lifecycle methods as the connection progresses:

| Method | When it is called |
|---|---|
| `onOpen()` | Once, immediately after the WebSocket handshake completes |
| `onMessage(WebSocketFrame $frame)` | For every frame received (text or binary) |
| `onClose(int $code)` | Once, when the connection closes (client or server initiated) |

`onMessage()` is the only abstract method. `onOpen()` and `onClose()` are optional
no-ops by default, so you only override what you need.

## Example

```php title="src/WebSocket/ChatHandler.php"
use Monadial\Nexus\Http\Handler\Attribute\FromActor;
use Monadial\Nexus\Http\Ws\WebSocket\Attribute\FromContext;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketContext;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketFrame;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketHandler;
use App\Actor\Message\ChatMessage;
use App\Actor\Message\UserConnected;
use App\Actor\Message\UserDisconnected;

final class ChatHandler extends WebSocketHandler
{
    /**
     * @param ActorRef<UserConnected|ChatMessage|UserDisconnected> $room
     */
    public function __construct(
        #[FromContext] private readonly WebSocketContext $ctx,
        #[FromActor('chat-room')] private readonly ActorRef $room,
    ) {}

    public function onOpen(): void
    {
        $this->ctx->send('Welcome to the chat!');
        $this->room->tell(new UserConnected($this->ctx->connectionId()));
    }

    public function onMessage(WebSocketFrame $frame): void
    {
        // Echo back to sender and forward to actor for broadcast
        $this->ctx->send('You said: ' . $frame->data);
        $this->room->tell(new ChatMessage($frame->data));
    }

    public function onClose(int $code): void
    {
        $this->room->tell(new UserDisconnected($this->ctx->connectionId(), $code));
    }
}

// Register the handler with the WsApplication DSL
$app->ws('/chat', ChatHandler::class);
```

## Key methods

- `onMessage(WebSocketFrame $frame): void` — **abstract**; handle an incoming frame.
  `$frame->data` holds the payload string; `$frame->opcode` is `1` for text, `2` for
  binary.
- `onOpen(): void` — optional; runs after handshake. Good for sending a welcome
  message or notifying an actor.
- `onClose(int $code): void` — optional; runs on disconnect. Standard close codes:
  `1000` normal, `1001` going away, `1006` abnormal.

## Injection attributes

- `#[FromContext]` on a constructor parameter injects the `WebSocketContext` bound
  to this connection.
- `#[FromActor('name')]` on a constructor parameter of type `ActorRef` injects a ref
  to the named actor registered with `WsApplication::actor()`.

## Full API reference

[Full class and method signatures](https://api.nexusactors.com/classes/Monadial-Nexus-Http-Ws-WebSocket-WebSocketHandler.html)

## See also

- [WebSockets guide](../../http/websockets) — WsApplication setup, routing modes,
  broadcast patterns
- [ActorContext](actor-context) — actor-side counterpart for sending messages to
  WebSocket connections
