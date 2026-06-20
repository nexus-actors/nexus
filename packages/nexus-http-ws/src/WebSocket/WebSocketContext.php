<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Ws\WebSocket;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Runtime context for an active WebSocket connection.
 *
 * A `WebSocketContext` is injected into a {@see WebSocketHandler} via the
 * `#[FromContext]` constructor attribute. It provides everything needed to
 * communicate with the connected client: send text or binary frames, issue a
 * ping, close the connection, and inspect the original HTTP upgrade request.
 * The context is scoped to a single connection and must not be shared across
 * connections.
 *
 * Example — echo handler using the context directly:
 * ```php
 * final class EchoHandler extends WebSocketHandler
 * {
 *     public function __construct(
 *         #[FromContext] private readonly WebSocketContext $ctx,
 *     ) {}
 *
 *     public function onMessage(WebSocketFrame $frame): void
 *     {
 *         if ($this->ctx->isAlive()) {
 *             $this->ctx->send($frame->data);
 *         }
 *     }
 *
 *     public function onClose(int $code): void
 *     {
 *         // Connection is already closed here; do not call send().
 *     }
 * }
 * ```
 *
 * @see WebSocketHandler  Abstract base class that receives this context via injection
 * @see WebSocketFrame    Represents an incoming WebSocket frame passed to onMessage()
 *
 * @psalm-api
 */
interface WebSocketContext
{
    /**
     * Returns the numeric connection identifier assigned by the server.
     */
    public function id(): int;

    /**
     * Returns the original HTTP upgrade request that initiated the connection.
     *
     * Useful for reading headers, cookies, or query parameters set during the
     * WebSocket handshake (e.g. authentication tokens or channel parameters).
     */
    public function request(): ServerRequestInterface;

    /**
     * Send a UTF-8 text frame to the client.
     *
     * @param string $text The UTF-8 encoded payload to transmit.
     */
    public function send(string $text): void;

    /**
     * Send a binary frame to the client.
     *
     * @param string $data The raw binary payload to transmit.
     */
    public function sendBinary(string $data): void;

    /**
     * Send a WebSocket ping frame to check connection liveness.
     *
     * The client should respond with a pong frame automatically.
     */
    public function sendPing(): void;

    /**
     * Close the WebSocket connection with an optional status code and reason.
     *
     * @param int    $code   WebSocket close code (default 1000 = normal closure).
     * @param string $reason Human-readable reason phrase sent to the client.
     */
    public function close(int $code = 1000, string $reason = ''): void;

    /**
     * Returns true if the connection is still open and accepting frames.
     */
    public function isAlive(): bool;
}
