<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Directory;

use NoDiscard;

/**
 * @psalm-api
 *
 * Maps actor paths to the worker ID that owns them.
 * Implementations: InMemoryDirectory (testing), SwooleTableDirectory (production).
 */
interface ActorDirectory
{
    public function register(string $path, int $workerId): void;

    #[NoDiscard]
    public function lookup(string $path): ?int;

    public function remove(string $path): void;

    #[NoDiscard]
    public function has(string $path): bool;
}
