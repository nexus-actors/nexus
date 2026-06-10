<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Actor;

use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Message\PoisonPill;
use Monadial\Nexus\Http\Exception\UnknownActorException;

/**
 * @psalm-api
 *
 * Per-request scope for actors spawned with the PerRequest mode. Lazy:
 * the first spawn() call triggers actor creation; subsequent calls with
 * the same name return the memoized ref. dispose() PoisonPills every
 * spawned actor and is idempotent.
 */
final class PerRequestActorScope
{
    /** @var array<string, ActorRef<object>> */
    private array $spawned = [];

    private bool $disposed = false;

    /**
     * @param array<string, ActorRegistrationEntry> $entries Per-request entries keyed by name.
     */
    public function __construct(
        private readonly ActorSystem $system,
        private readonly array $entries,
        private readonly string $requestId,
    ) {}

    public function dispose(): void
    {
        if ($this->disposed) {
            return;
        }

        $this->disposed = true;

        foreach ($this->spawned as $ref) {
            if ($ref->isAlive()) {
                $ref->tell(new PoisonPill());
            }
        }
    }

    public function hasSpawned(string $name): bool
    {
        return isset($this->spawned[$name]);
    }

    /** @return ActorRef<object> */
    public function spawn(string $name): ActorRef
    {
        if ($this->disposed) {
            throw new UnknownActorException("Scope disposed; cannot spawn '{$name}'");
        }

        if (isset($this->spawned[$name])) {
            return $this->spawned[$name];
        }

        $entry = $this->entries[$name] ?? throw new UnknownActorException($name);

        $actorName = "{$entry->name}-{$this->requestId}";
        $ref = $this->system->spawn($entry->props, $actorName);
        $this->spawned[$name] = $ref;

        return $ref;
    }
}
