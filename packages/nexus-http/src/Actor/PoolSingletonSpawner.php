<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Actor;

use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\Props;

/**
 * @psalm-api
 *
 * Abstraction for spawning a pool-singleton actor. Concrete implementations
 * live in HTTP server packages — e.g., a worker-pool-backed implementation
 * wraps WorkerNode::spawn from nexus-worker-pool-swoole.
 *
 * Decouples nexus-http core from any specific clustering / threading impl.
 */
interface PoolSingletonSpawner
{
    /** @return ActorRef<object> */
    public function spawn(Props $props, string $name): ActorRef;
}
