<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Ws\WebSocket;

use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Http\Ws\WebSocket\Exception\ChannelCapacityExceededException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function count;

/**
 * @psalm-api
 *
 * Spawns and caches one channel actor per stable name. Cached refs are
 * validated against `isAlive()` on every lookup, so a channel actor that
 * stopped itself (e.g. after the last WS connection closed) is silently
 * pruned and the next connect spawns a fresh one — no per-game leak.
 *
 * A cardinality cap bounds the number of LIVE channel actors: churning
 * unique keys past the cap is refused with {@see ChannelCapacityExceededException}
 * rather than spawning actors, refs, and mailboxes without limit (SEC-002).
 * Dead entries are pruned before the cap is enforced, so honest churn that
 * stops channels on last-close never trips it. Used by WebSocketDispatcher;
 * not part of the user-facing API.
 */
final class ChannelActorRegistry
{
    /** Default maximum number of simultaneously live channel actors. */
    public const int DEFAULT_MAX_CHANNELS = 10_000;

    /** @var array<string, ActorRef> */
    private array $refs = [];

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly ActorSystem $system,
        ?LoggerInterface $logger = null,
        private readonly int $maxChannels = self::DEFAULT_MAX_CHANNELS,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @throws ChannelCapacityExceededException when spawning a new channel
     *         would exceed the cardinality cap.
     */
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

        // Enforce the cap for genuinely NEW channels only, after pruning any
        // dead refs so last-close-stopped channels free their slot.
        $this->pruneDead();

        if (count($this->refs) >= $this->maxChannels) {
            $this->logger->warning('ChannelActorRegistry: cardinality cap reached', [
                'cap' => $this->maxChannels,
                'name' => $name,
            ]);

            throw new ChannelCapacityExceededException($this->maxChannels);
        }

        $this->logger->debug('ChannelActorRegistry: spawning new actor', ['name' => $name]);
        $ref = $this->system->spawn($props, $name);
        $this->refs[$name] = $ref;

        return $ref;
    }

    /** Number of currently tracked (not yet pruned) channel actors. */
    public function count(): int
    {
        return count($this->refs);
    }

    public function remove(string $name): void
    {
        if (isset($this->refs[$name])) {
            $this->logger->debug('ChannelActorRegistry: removed actor from cache', ['name' => $name]);
        }

        unset($this->refs[$name]);
    }

    private function pruneDead(): void
    {
        foreach ($this->refs as $name => $ref) {
            if (!$ref->isAlive()) {
                unset($this->refs[$name]);
            }
        }
    }
}
