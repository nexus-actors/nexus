<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Actor;

use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Example\TicTacToe\ReadModel\GameReadModel;
use Monadial\Nexus\Persistence\Event\EventStore;
use Psr\Log\LoggerInterface;

/**
 * Locates (or lazily spawns) the one event-sourced {@see GameActor} for a
 * game id — the ES counterpart of the Doctrine `EntityRefFactory`.
 *
 * Single-writer per id: one actor owns the game's command stream. A cached
 * ref that has died (parent stop, worker recycle) is pruned and respawned;
 * the persistence engine replays the event log to rebuild state, so a
 * respawn is transparent to callers. `ActorSystem::spawn()` itself also
 * prunes a same-named dead child, so the liveness check here is belt-and-
 * suspenders that also avoids re-spawning a live actor.
 */
final class GameRefFactory
{
    /** @var array<string, ActorRef<object>> */
    private array $cache = [];

    public function __construct(
        private readonly ActorSystem $system,
        private readonly EventStore $store,
        private readonly GameReadModel $readModel,
        private readonly LoggerInterface $log,
    ) {}

    /**
     * @return ActorRef<object>
     */
    public function of(string $gameId): ActorRef
    {
        $name = 'game-' . $gameId;

        if (isset($this->cache[$name]) && $this->cache[$name]->isAlive()) {
            return $this->cache[$name];
        }

        unset($this->cache[$name]);

        $ref = $this->system->spawn(
            Props::fromBehavior(GameActor::behavior($gameId, $this->store, $this->readModel, $this->log)),
            $name,
        );

        return $this->cache[$name] = $ref;
    }
}
