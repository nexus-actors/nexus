<?php

declare(strict_types=1);

namespace Monadial\Nexus\WorkerPool\Tests\Unit\Directory;

use Monadial\Nexus\WorkerPool\Directory\InMemoryWorkerDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(InMemoryWorkerDirectory::class)]
final class InMemoryWorkerDirectoryTest extends TestCase
{
    #[Test]
    public function registerAndLookup(): void
    {
        $dir = new InMemoryWorkerDirectory();
        $dir->register('/user/orders', 3);

        self::assertSame(3, $dir->lookup('/user/orders'));
    }

    #[Test]
    public function lookupUnknownReturnsNull(): void
    {
        $dir = new InMemoryWorkerDirectory();
        self::assertNull($dir->lookup('/user/missing'));
    }

    #[Test]
    public function has(): void
    {
        $dir = new InMemoryWorkerDirectory();
        $dir->register('/user/orders', 2);

        self::assertTrue($dir->has('/user/orders'));
        self::assertFalse($dir->has('/user/missing'));
    }
}
