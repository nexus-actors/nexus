<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Ws\WebSocket;

use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Props;

/**
 * @psalm-api
 *
 * Spawns and caches one channel actor per stable name. Used by
 * WebSocketDispatcher; not part of the user-facing API.
 */
final class ChannelActorRegistry
{
    /** @var array<string, ActorRef> */
    private array $refs = [];

    public function __construct(private readonly ActorSystem $system) {}

    public function resolveOrSpawn(string $name, Props $props): ActorRef
    {
        if (isset($this->refs[$name])) {
            return $this->refs[$name];
        }

        $ref = $this->system->spawn($props, $name);
        $this->refs[$name] = $ref;

        return $ref;
    }

    public function remove(string $name): void
    {
        unset($this->refs[$name]);
    }
}
