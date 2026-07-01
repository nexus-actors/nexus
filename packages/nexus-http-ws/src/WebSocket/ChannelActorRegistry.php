<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Ws\WebSocket;

use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Props;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @psalm-api
 *
 * Spawns and caches one channel actor per stable name. Cached refs are
 * validated against `isAlive()` on every lookup, so a channel actor that
 * stopped itself (e.g. after the last WS connection closed) is silently
 * pruned and the next connect spawns a fresh one — no per-game leak.
 * Used by WebSocketDispatcher; not part of the user-facing API.
 */
final class ChannelActorRegistry
{
    /** @var array<string, ActorRef> */
    private array $refs = [];

    private readonly LoggerInterface $logger;

    public function __construct(private readonly ActorSystem $system, ?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    public function resolveOrSpawn(string $name, Props $props): ActorRef
    {
        $existing = $this->refs[$name] ?? null;

        if ($existing !== null && $existing->isAlive()) {
            $this->logger->debug('ChannelActorRegistry: reusing existing actor', ['name' => $name]);

            return $existing;
        }

        if ($existing !== null) {
            $this->logger->debug('ChannelActorRegistry: pruning stopped actor', ['name' => $name]);
            unset($this->refs[$name]);
        }

        $this->logger->debug('ChannelActorRegistry: spawning new actor', ['name' => $name]);
        $ref = $this->system->spawn($props, $name);
        $this->refs[$name] = $ref;

        return $ref;
    }

    public function remove(string $name): void
    {
        if (isset($this->refs[$name])) {
            $this->logger->debug('ChannelActorRegistry: removed actor from cache', ['name' => $name]);
        }

        unset($this->refs[$name]);
    }
}
