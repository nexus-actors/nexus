<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp;

/**
 * @psalm-api
 *
 * A bidirectional link between two cluster nodes. Frames flow in both
 * directions; callbacks fire on frame arrival and link closure.
 *
 * Implementations: LoopbackPeerLink (in-process, C1.3), TcpPeerLink (Swoole, C1.4).
 *
 * Usage:
 *
 *   $link->onFrame(function (Frame $frame): void {
 *       // handle incoming frame
 *   });
 *   $link->onClose(function (): void {
 *       // peer disconnected
 *   });
 *   $link->sendFrame(new Frame(FrameType::Ping, ''));
 */
interface PeerLink
{
    /**
     * Send a frame to the remote peer.
     * No-op if the link is already closed.
     */
    public function sendFrame(Frame $frame): void;

    /**
     * Register a handler for frames arriving from the remote peer.
     * Multiple handlers may be registered; all are called in registration order.
     *
     * @param callable(Frame): void $onFrame
     */
    public function onFrame(callable $onFrame): void;

    /**
     * Register a handler invoked when this link is closed (by either end).
     * Multiple handlers may be registered; all are called in registration order.
     *
     * @param callable(): void $onClose
     */
    public function onClose(callable $onClose): void;

    /**
     * Close this end of the link. The remote peer's onClose callbacks fire
     * asynchronously (on the next runtime tick). Idempotent.
     */
    public function close(): void;

    /**
     * The remote peer's endpoint, or null when the remote address is not known.
     * For LoopbackMeshTransport the server-side link returns null because
     * loopback clients do not have a fixed network address.
     */
    public function remote(): ?NodeEndpoint;
}
