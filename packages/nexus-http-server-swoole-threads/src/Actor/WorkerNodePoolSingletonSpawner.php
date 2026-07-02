<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\Threads\Actor;

use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Http\Actor\PoolSingletonSpawner;
use Monadial\Nexus\WorkerPool\WorkerNode;
use Override;

/**
 * @psalm-api
 *
 * Adapts WorkerNode::spawn (routes via the consistent hash ring across
 * threads) to nexus-http's PoolSingletonSpawner contract.
 *
 * Pass to HttpApp::withPoolSingletonSpawner() inside the factory closure
 * to enable pool-singleton actor mode in thread-mode apps.
 */
final readonly class WorkerNodePoolSingletonSpawner implements PoolSingletonSpawner
{
    public function __construct(private WorkerNode $node) {}

    #[Override]
    public function spawn(Props $props, string $name): ActorRef
    {
        return $this->node->spawn($props, $name);
    }
}
