<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server\Swoole\WebSocket;

use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Props;

/**
 * @psalm-api
 *
 * Per-worker cache: actor name -> ActorRef. Lazy-spawns the channel actor
 * on first lookup; subsequent connections to the same channel reuse.
 */
final class ChannelActorRegistry
{
    /** @var array<string, ActorRef<object>> */
    private array $cache = [];

    public function __construct(private readonly ActorSystem $system)
    {
    }

    /**
     * @param Props<object> $props
     * @return ActorRef<object>
     */
    public function resolveOrSpawn(string $name, Props $props): ActorRef
    {
        if (isset($this->cache[$name])) {
            return $this->cache[$name];
        }

        $ref = $this->system->spawn($props, $name);
        $this->cache[$name] = $ref;

        return $ref;
    }

    public function remove(string $name): void
    {
        unset($this->cache[$name]);
    }
}
