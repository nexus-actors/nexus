<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tests\Unit\Directory;

use Monadial\Nexus\Cluster\Directory\InMemoryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(InMemoryDirectory::class)]
final class InMemoryDirectoryTest extends TestCase
{
    #[Test]
    public function registerAndLookup(): void
    {
        $dir = new InMemoryDirectory();

        $dir->register('/user/orders', 3);

        self::assertSame(3, $dir->lookup('/user/orders'));
    }

    #[Test]
    public function lookupReturnsNullForUnknownPath(): void
    {
        $dir = new InMemoryDirectory();

        self::assertNull($dir->lookup('/user/nonexistent'));
    }

    #[Test]
    public function removeDeletesEntry(): void
    {
        $dir = new InMemoryDirectory();
        $dir->register('/user/orders', 3);

        $dir->remove('/user/orders');

        self::assertNull($dir->lookup('/user/orders'));
    }

    #[Test]
    public function hasReturnsTrueForRegisteredPath(): void
    {
        $dir = new InMemoryDirectory();
        $dir->register('/user/orders', 3);

        self::assertTrue($dir->has('/user/orders'));
        self::assertFalse($dir->has('/user/unknown'));
    }

    #[Test]
    public function registerOverwritesExistingEntry(): void
    {
        $dir = new InMemoryDirectory();
        $dir->register('/user/orders', 3);
        $dir->register('/user/orders', 7);

        self::assertSame(7, $dir->lookup('/user/orders'));
    }
}
