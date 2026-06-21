<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Ws\WebSocket;

/**
 * Base class for WebSocket connection handlers; extend to define per-connection behaviour.
 *
 * One `WebSocketHandler` instance is created per WebSocket connection. The
 * framework resolves it through the PSR-11 container so constructor parameters
 * participate in dependency injection. Two special injection attributes are
 * available:
 *
 * - `#[FromContext]` — injects the live {@see WebSocketContext} for the current
 *   connection, giving access to `send()`, `close()`, and connection metadata.
 * - `#[FromActor('name')]` — injects an `ActorRef` to a named actor registered
 *   with the parent {@see WsApplication}.
 *
 * Override the three lifecycle methods to react to connection events.
 * `onMessage()` is the only abstract method; `onOpen()` and `onClose()` are
 * optional no-ops by default.
 *
 * Example — echo handler:
 * ```php
 * final class EchoHandler extends WebSocketHandler
 * {
 *     public function __construct(
 *         #[FromContext] private readonly WebSocketContext $ctx,
 *     ) {}
 *
 *     public function onMessage(WebSocketFrame $frame): void
 *     {
 *         $this->ctx->send($frame->data);
 *     }
 *
 *     public function onClose(int $code): void
 *     {
 *         // cleanup on disconnect
 *     }
 * }
 *
 * // Register the handler:
 * $app->ws('/chat', EchoHandler::class);
 * ```
 *
 * @see WebSocketContext   Runtime context providing send/close and connection info
 * @see WsApplication      DSL for registering WebSocket routes via ws() / channel()
 * @see WebSocketFrame     Represents an incoming WebSocket frame
 *
 * @psalm-api
 */
abstract class WebSocketHandler
{
    /**
     * Invoked for every WebSocket frame received on this connection.
     *
     * @param WebSocketFrame $frame The incoming frame, including opcode and payload.
     */
    abstract public function onMessage(WebSocketFrame $frame): void;

    /**
     * Invoked once when the WebSocket handshake completes and the connection is open.
     *
     * Default implementation is a no-op; override to perform connection setup.
     */
    public function onOpen(): void
    {
        // Default: no-op.
    }

    /**
     * Invoked when the WebSocket connection is closed, either by the client or the server.
     *
     * @param int $code WebSocket close code (e.g. 1000 for normal closure).
     */
    public function onClose(int $code): void
    {
        // Default: no-op.
    }
}
