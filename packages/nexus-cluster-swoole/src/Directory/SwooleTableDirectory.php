<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Swoole\Directory;

use Monadial\Nexus\Cluster\Directory\ActorDirectory;
use NoDiscard;
use Override;
use Swoole\Table;

/**
 * @psalm-api
 *
 * Swoole\Table-backed actor directory.
 * Shared memory — O(1) lookup, visible to all worker processes.
 */
final readonly class SwooleTableDirectory implements ActorDirectory
{
    public function __construct(private Table $table) {}

    #[Override]
    public function register(string $path, int $workerId): void
    {
        $this->table->set($path, ['worker_id' => $workerId]);
    }

    #[Override]
    #[NoDiscard]
    public function lookup(string $path): ?int
    {
        /** @var array{worker_id: int}|false $row */
        $row = $this->table->get($path);

        if ($row === false) {
            return null;
        }

        return $row['worker_id'];
    }

    #[Override]
    #[NoDiscard]
    public function has(string $path): bool
    {
        /** @var bool */
        return $this->table->exists($path);
    }

    #[Override]
    public function remove(string $path): void
    {
        $this->table->del($path);
    }

    /**
     * Create a Swoole\Table configured for the actor directory.
     * Call this once in the master process before starting workers.
     */
    public static function createTable(int $size = 65536): Table
    {
        $table = new Table($size);
        $table->column('worker_id', Table::TYPE_INT);
        $table->create();

        return $table;
    }
}
