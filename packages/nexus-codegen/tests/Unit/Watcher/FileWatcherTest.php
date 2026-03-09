<?php

declare(strict_types=1);

namespace Monadial\Nexus\Codegen\Tests\Unit\Watcher;

use Monadial\Nexus\Codegen\Watcher\FileWatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(FileWatcher::class)]
final class FileWatcherTest extends TestCase
{
    #[Test]
    public function detects_changed_file(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'nexus-watch-');
        self::assertIsString($file);

        $watcher = new FileWatcher([$file], intervalMs: 10);

        $changed = [];
        $watcher->onChange(function (string $path) use (&$changed): void {
            $changed[] = $path;
        });

        sleep(1);
        touch($file);

        $watcher->tick();

        self::assertContains($file, $changed);
        unlink($file);
    }

    #[Test]
    public function does_not_trigger_when_file_unchanged(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'nexus-watch-');
        self::assertIsString($file);

        $watcher = new FileWatcher([$file], intervalMs: 10);

        $changed = [];
        $watcher->onChange(function (string $path) use (&$changed): void {
            $changed[] = $path;
        });

        $watcher->tick();

        self::assertNotContains($file, $changed);
        unlink($file);
    }
}
