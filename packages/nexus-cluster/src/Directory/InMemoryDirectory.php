<?php
declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Directory;

use NoDiscard;
use Override;

/**
 * @psalm-api
 *
 * In-memory actor directory for unit testing.
 */
final class InMemoryDirectory implements ActorDirectory
{
    /** @var array<string, int> */
    private array $entries = [];

    #[Override]
    public function register(string $path, int $workerId): void
    {
        $this->entries[$path] = $workerId;
    }

    #[Override]
    #[NoDiscard]
    public function lookup(string $path): ?int
    {
        return $this->entries[$path] ?? null;
    }

    #[Override]
    public function remove(string $path): void
    {
        unset($this->entries[$path]);
    }

    #[Override]
    #[NoDiscard]
    public function has(string $path): bool
    {
        return isset($this->entries[$path]);
    }
}
