<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Loopback;

use Monadial\Nexus\Cluster\Tcp\MeshTransport;
use Monadial\Nexus\Cluster\Tcp\NodeEndpoint;
use Monadial\Nexus\Cluster\Tcp\PeerLink;
use Monadial\Nexus\Runtime\Runtime\Runtime;
use Override;
use RuntimeException;

use function sprintf;

/**
 * @psalm-api
 *
 * Pure-PHP in-process mesh transport backed by a shared LoopbackHub.
 *
 * This is the Fiber dev/test transport for nexus-cluster-tcp — it requires no
 * ext-swoole and no real network sockets. It is shipped in src/ (not test support)
 * because application code uses it in local development and integration tests.
 *
 * Hub design: a single LoopbackHub is shared across all transport instances that
 * form a loopback cluster. This is necessary because each transport only knows
 * about its own listeners; the hub is the rendezvous point.
 *
 *   $hub  = new LoopbackHub();
 *   $nodeA = new LoopbackMeshTransport($hub, $runtime);   // serves on port 7001
 *   $nodeB = new LoopbackMeshTransport($hub, $runtime);   // connects to port 7001
 *
 * Async delivery: connect() spawns a runtime task for the onAccept callback;
 * PeerLink.sendFrame() spawns a task per frame. Both use Runtime::spawn() so
 * they run cooperatively on the next event-loop tick — identical semantics to
 * a real TCP peer connection.
 *
 * connect() to an unserved endpoint throws RuntimeException immediately (fail-fast:
 * in loopback mode, connecting before serve() is a programming error).
 */
final class LoopbackMeshTransport implements MeshTransport
{
    /** @var list<NodeEndpoint> */
    private array $bound = [];

    public function __construct(private readonly LoopbackHub $hub, private readonly Runtime $runtime) {}

    #[Override]
    public function connect(NodeEndpoint $endpoint): PeerLink
    {
        $listener = $this->hub->findListener($endpoint);

        if ($listener === null) {
            throw new RuntimeException(
                sprintf(
                    "LoopbackMeshTransport: no server is listening on '%s'. "
                    . 'Call serve() on the target transport before connect().',
                    $endpoint,
                ),
            );
        }

        // Client link: remote() = the endpoint it connected to.
        // Server link: remote() = null (loopback clients have no fixed address).
        $clientLink = new LoopbackPeerLink($this->runtime, $endpoint);
        $serverLink = new LoopbackPeerLink($this->runtime, null);

        $clientLink->linkPeer($serverLink);
        $serverLink->linkPeer($clientLink);

        // Deliver the server-side link to the listener asynchronously so that
        // onAccept runs on the next runtime tick (mirrors real TCP accept behaviour).
        $this->runtime->spawn(static function () use ($listener, $serverLink): void {
            $listener($serverLink);
        });

        return $clientLink;
    }

    #[Override]
    public function serve(NodeEndpoint $bind, callable $onAccept): void
    {
        $this->hub->register($bind, $onAccept);
        $this->bound[] = $bind;
    }

    #[Override]
    public function close(): void
    {
        foreach ($this->bound as $endpoint) {
            $this->hub->unregister($endpoint);
        }

        $this->bound = [];
    }
}
