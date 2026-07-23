<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Tests\Support;

use Monadial\Nexus\Cluster\Tcp\DeliveryOutcome;
use Monadial\Nexus\Cluster\Tcp\NodeEndpoint;
use Monadial\Nexus\Cluster\Tcp\Protocol\Frame;
use Monadial\Nexus\Cluster\Tcp\Transport\PeerLink;
use Override;

/**
 * Minimal synchronous {@see PeerLink} test double. Unlike LoopbackPeerLink, `onFrame`/`onClose`
 * handlers are invoked directly by the test via {@see receiveFrame()}/{@see triggerClose()} —
 * no runtime spawn, no peer object, no event loop required.
 *
 * `close()` mirrors LoopbackPeerLink's real semantics: closing THIS end does NOT fire this same
 * end's own `onClose` handlers (only the remote peer's would fire, on ITS end, when it learns of
 * the disconnect) — it only records that close() was called, via {@see wasClosed()}. Use
 * {@see triggerClose()} to simulate "the remote peer disconnected", which is what actually fires
 * the registered close handlers.
 */
final class FakePeerLink implements PeerLink
{
    /** @var list<callable(Frame): void> */
    private array $frameHandlers = [];

    /** @var list<callable(): void> */
    private array $closeHandlers = [];

    /** @var list<Frame> */
    private array $sent = [];

    private bool $closeCalled = false;

    private bool $handlersFired = false;

    public function __construct(private readonly ?NodeEndpoint $remoteEndpoint = null) {}

    #[Override]
    public function sendFrame(Frame $frame): DeliveryOutcome
    {
        $this->sent[] = $frame;

        return DeliveryOutcome::Admitted;
    }

    #[Override]
    public function onFrame(callable $onFrame): void
    {
        $this->frameHandlers[] = $onFrame;
    }

    #[Override]
    public function onClose(callable $onClose): void
    {
        $this->closeHandlers[] = $onClose;
    }

    #[Override]
    public function close(): void
    {
        $this->closeCalled = true;
    }

    #[Override]
    public function remote(): ?NodeEndpoint
    {
        return $this->remoteEndpoint;
    }

    /**
     * Deliver `$frame` to every registered onFrame handler, as the remote peer sending it would.
     */
    public function receiveFrame(Frame $frame): void
    {
        foreach ($this->frameHandlers as $handler) {
            $handler($frame);
        }
    }

    /**
     * Simulate this link closing — as the remote peer disconnecting (or this side detecting the
     * disconnect) would. Fires every registered onClose handler exactly once; idempotent.
     */
    public function triggerClose(): void
    {
        if ($this->handlersFired) {
            return;
        }

        $this->handlersFired = true;

        foreach ($this->closeHandlers as $handler) {
            $handler();
        }
    }

    /**
     * Whether {@see close()} was called on this end (does not imply the close handlers fired).
     */
    public function wasClosed(): bool
    {
        return $this->closeCalled;
    }

    /** @return list<Frame> */
    public function sentFrames(): array
    {
        return $this->sent;
    }
}
