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
final readonly class ThreadMapDirectory implements WorkerDirectory
{
    public function __construct(private Map $map) {}

    #[Override]
    public function register(string $path, int $workerId): void
    {
        $this->map[$path] = $workerId;
    }

    #[Override]
    public function lookup(string $path): ?int
    {
        return self::asInt($this->map[$path] ?? null);
    }

    #[Override]
    public function has(string $path): bool
    {
        return isset($this->map[$path]);
    }

    /**
     * The shared Map stores mixed values; the directory only ever writes
     * worker IDs (ints), so anything else reads as "not registered".
     */
    private static function asInt(mixed $value): ?int
    {
        return is_int($value)
            ? $value
            : null;
    }
}
