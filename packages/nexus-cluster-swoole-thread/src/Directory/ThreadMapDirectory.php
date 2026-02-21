<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\SwooleThread\Directory;

use Monadial\Nexus\Cluster\Directory\ActorDirectory;
use NoDiscard;
use Override;
use Swoole\Thread\Map;

/**
 * @psalm-api
 * @psalm-suppress UndefinedClass
 *
 * Thread\Map-backed actor directory.
 * Thread-safe — built-in locking, visible to all worker threads.
 *
 * Swoole Thread classes require PHP ZTS and are not covered by swoole/ide-helper stubs.
 */
final readonly class ThreadMapDirectory implements ActorDirectory
{
    public function __construct(private Map $map) {}

    #[Override]
    public function register(string $path, int $workerId): void
    {
        /** @psalm-suppress MixedArrayAssignment */
        $this->map[$path] = $workerId;
    }

    #[Override]
    #[NoDiscard]
    public function lookup(string $path): ?int
    {
        /** @var int|null */
        return $this->map[$path] ?? null;
    }

    #[Override]
    public function remove(string $path): void
    {
        /** @psalm-suppress MixedArrayAccess */
        unset($this->map[$path]);
    }

    #[Override]
    #[NoDiscard]
    public function has(string $path): bool
    {
        /** @psalm-suppress MixedArrayAccess */
        return isset($this->map[$path]);
    }
}
