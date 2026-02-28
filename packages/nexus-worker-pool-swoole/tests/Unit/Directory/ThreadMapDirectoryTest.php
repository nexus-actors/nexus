<?php

declare(strict_types=1);

namespace Monadial\Nexus\WorkerPool\Swoole\Tests\Unit\Directory;

use Monadial\Nexus\WorkerPool\Swoole\Directory\ThreadMapDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Thread\Map;

#[CoversClass(ThreadMapDirectory::class)]
final class ThreadMapDirectoryTest extends TestCase
{
    #[Test]
    public function registerAndLookup(): void
    {
        $map = new Map();
        $dir = new ThreadMapDirectory($map);

        $dir->register('/user/orders', 3);

        self::assertSame(3, $dir->lookup('/user/orders'));
    }

    #[Test]
    public function lookupUnknownReturnsNull(): void
    {
        $dir = new ThreadMapDirectory(new Map());

        self::assertNull($dir->lookup('/user/missing'));
    }

    #[Test]
    public function has(): void
    {
        $map = new Map();
        $dir = new ThreadMapDirectory($map);
        $dir->register('/user/orders', 2);

        self::assertTrue($dir->has('/user/orders'));
        self::assertFalse($dir->has('/user/missing'));
    }

    #[Test]
    public function mapIsSharedByReference(): void
    {
        $map = new Map();
        $dir1 = new ThreadMapDirectory($map);
        $dir2 = new ThreadMapDirectory($map);

        $dir1->register('/user/orders', 1);

        self::assertSame(1, $dir2->lookup('/user/orders'));
    }
}
