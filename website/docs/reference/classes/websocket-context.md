---
title: WebSocketContext
sidebar_position: 21
related:
  - http/websockets
  - reference/classes/websocket-handler
---

# WebSocketContext

Runtime context for an active WebSocket connection; provides `send()`, `close()`, and
access to the original HTTP upgrade request.

## What it does

`WebSocketContext` is injected into a `WebSocketHandler` via the `#[FromContext]`
constructor attribute. It is scoped to a single connection — one instance exists for
the lifetime of that connection and must not be shared across connections.

The context covers four areas:

| Area | Methods |
|---|---|
| Sending data | `send(string $text)`, `sendBinary(string $data)` |
| Connection control | `close(int $code, string $reason)`, `sendPing()` |
| Connection state | `isAlive(): bool`, `id(): int` |
| Upgrade request | `request(): ServerRequestInterface` |

`isAlive()` is useful in async send loops — check it before sending to avoid
writing to a connection that closed between iterations.

`request()` exposes the original HTTP upgrade request, including headers, cookies,
and query parameters set during the WebSocket handshake (authentication tokens,
channel parameters, etc.).

## Example

```php title="src/WebSocket/EchoHandler.php"
use Monadial\Nexus\Http\Ws\Attribute\FromContext;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketContext;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketFrame;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketHandler;

final class EchoHandler extends WebSocketHandler
{
    public function __construct(
        #[FromContext] private readonly WebSocketContext $ctx,
    ) {}

    public function onOpen(): void
    {
        $principal = $this->ctx->request()->getAttribute('principal');
        $this->ctx->send("Hello, {$principal->id()}!");
    }

    public function onMessage(WebSocketFrame $frame): void
    {
        if ($this->ctx->isAlive()) {
            $this->ctx->send($frame->data);
        }
    }

    public function onClose(int $code): void
    {
        // Connection is already closed here — do not call send().
    }
}
```

## Key methods

- `id(): int` — numeric connection identifier assigned by the server.
- `request(): ServerRequestInterface` — original HTTP upgrade request; use to read
  auth tokens, cookies, or handshake query parameters.
- `send(string $text): void` — send a UTF-8 text frame to the client.
- `sendBinary(string $data): void` — send a raw binary frame.
- `sendPing(): void` — send a WebSocket ping; the client responds with a pong
  automatically.
- `close(int $code = 1000, string $reason = ''): void` — initiate a graceful close.
  `1000` = normal closure.
- `isAlive(): bool` — returns `true` if the connection is still open and accepting
  frames.

## Full API reference

[Full interface and method signatures](https://api.nexusactors.com/classes/Monadial-Nexus-Http-Ws-WebSocket-WebSocketContext.html)

## See also

- [WebSockets guide](../../http/websockets) — WsApplication setup, routing modes,
  broadcast patterns
- [WebSocketHandler](websocket-handler) — the abstract handler class that receives
  this context via `#[FromContext]` injection
