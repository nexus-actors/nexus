<?php

declare(strict_types=1);

namespace Monadial\Nexus\WorkerPool\Directory;

use Override;

/** @psalm-api */
final class InMemoryWorkerDirectory implements WorkerDirectory
{
    /** @var array<string, int> */
    private array $map = [];

    #[Override]
    public function register(string $path, int $workerId): void
    {
        $this->map[$path] = $workerId;
    }

    #[Override]
    public function lookup(string $path): ?int
    {
        return $this->map[$path] ?? null;
    }

    #[Override]
    public function has(string $path): bool
    {
        return isset($this->map[$path]);
    }
}
