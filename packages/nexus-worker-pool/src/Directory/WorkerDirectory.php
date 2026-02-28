<?php

declare(strict_types=1);

namespace Monadial\Nexus\WorkerPool\Directory;

/**
 * @psalm-api
 *
 * Maps actor paths to worker IDs within a local worker pool.
 */
interface WorkerDirectory
{
    public function register(string $path, int $workerId): void;

    public function lookup(string $path): ?int;

    public function has(string $path): bool;
}
