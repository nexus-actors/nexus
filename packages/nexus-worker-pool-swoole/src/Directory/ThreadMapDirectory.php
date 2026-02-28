<?php

declare(strict_types=1);

namespace Monadial\Nexus\WorkerPool\Swoole\Directory;

use Monadial\Nexus\WorkerPool\Directory\WorkerDirectory;
use Override;
use Swoole\Thread\Map;

/**
 * @psalm-api
 *
 * Thread-safe actor directory backed by Swoole\Thread\Map.
 * All workers share the same Map instance — Thread\Map handles
 * internal synchronization, no explicit locking needed.
 */
final class ThreadMapDirectory implements WorkerDirectory
{
    public function __construct(private readonly Map $map) {}

    /**
     * @psalm-suppress InaccessibleProperty, InvalidArgument
     */
    #[Override]
    public function register(string $path, int $workerId): void
    {
        $this->map[$path] = $workerId;
    }

    /**
     * @psalm-suppress InvalidArgument
     */
    #[Override]
    public function lookup(string $path): ?int
    {
        $value = $this->map[$path] ?? null;

        return $value !== null
            ? (int) $value
            : null;
    }

    /**
     * @psalm-suppress InvalidArgument
     */
    #[Override]
    public function has(string $path): bool
    {
        return isset($this->map[$path]);
    }
}
