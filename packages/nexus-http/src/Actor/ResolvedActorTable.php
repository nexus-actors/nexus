<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Actor;

use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\Exception\PoolSingletonRequiresSpawnerException;
use Monadial\Nexus\Http\Exception\UnknownActorException;

/**
 * @psalm-api
 *
 * Compiled actor lookup table built by HttpApp::compile(). Caches resolved
 * refs for pool-singleton + worker-local modes; provides a factory shape
 * for per-request actors.
 */
final readonly class ResolvedActorTable
{
    /**
     * @param array<string, ActorRef<object>> $resolved
     * @param array<string, ActorRegistrationEntry> $perRequestEntries
     */
    private function __construct(private array $resolved, private array $perRequestEntries) {}

    /**
     * @param list<ActorRegistrationEntry> $entries
     */
    public static function build(array $entries, ActorSystem $system, ?PoolSingletonSpawner $spawner): self
    {
        $resolved = [];
        $perRequest = [];
        $poolSingletonsMissingSpawner = [];

        foreach ($entries as $entry) {
            if ($entry->mode === ActorMode::PerRequest) {
                $perRequest[$entry->name] = $entry;
            } elseif ($entry->mode === ActorMode::WorkerLocal) {
                $resolved[$entry->name] = $system->spawn($entry->props, $entry->name);
            } elseif ($spawner === null) {
                $poolSingletonsMissingSpawner[] = $entry->name;
            } else {
                $resolved[$entry->name] = $spawner->spawn($entry->props, $entry->name);
            }
        }

        if ($poolSingletonsMissingSpawner !== []) {
            throw new PoolSingletonRequiresSpawnerException($poolSingletonsMissingSpawner);
        }

        return new self($resolved, $perRequest);
    }

    public function hasAny(string $name): bool
    {
        return isset($this->resolved[$name]) || isset($this->perRequestEntries[$name]);
    }

    public function isPerRequest(string $name): bool
    {
        return isset($this->perRequestEntries[$name]);
    }

    /** @return array<string, ActorRegistrationEntry> */
    public function perRequestEntries(): array
    {
        return $this->perRequestEntries;
    }

    /** @return ActorRef<object> */
    public function resolve(string $name): ActorRef
    {
        return $this->resolved[$name] ?? throw new UnknownActorException($name);
    }
}
