<?php

declare(strict_types=1);

namespace Monadial\Nexus\Codegen\Watcher;

final class FileWatcher
{
    /** @var array<string, int> filepath → last mtime */
    private array $mtimes = [];

    /** @var callable(string): void */
    private $callback;

    /** @param array<string> $files */
    public function __construct(private readonly array $files, private readonly int $intervalMs = 500,) {
        foreach ($files as $file) {
            $mtime = filemtime($file);
            $this->mtimes[$file] = $mtime !== false
                ? $mtime
                : 0;
        }

        $this->callback = static function (string $_file): void {};
    }

    /** @param callable(string): void $callback */
    public function onChange(callable $callback): void
    {
        $this->callback = $callback;
    }

    public function tick(): void
    {
        foreach ($this->files as $file) {
            $raw = filemtime($file);
            $mtime = $raw !== false
                ? $raw
                : 0;

            if ($mtime > ($this->mtimes[$file] ?? 0)) {
                $this->mtimes[$file] = $mtime;
                ($this->callback)($file);
            }
        }
    }

    public function run(): never
    {
        while (true) {
            $this->tick();
            usleep($this->intervalMs * 1000);
        }
    }
}
