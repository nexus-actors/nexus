<?php
declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Cluster;

use Monadial\Nexus\Cluster\Swoole\Directory\SwooleTableDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Table;

#[CoversClass(SwooleTableDirectory::class)]
#[RequiresPhpExtension('swoole')]
final class SwooleTableDirectoryTest extends TestCase
{
    private Table $table;
    private SwooleTableDirectory $directory;

    #[Test]
    public function registerAndLookup(): void
    {
        $this->directory->register('/user/orders', 3);

        self::assertSame(3, $this->directory->lookup('/user/orders'));
    }

    #[Test]
    public function lookupReturnsNullForUnknown(): void
    {
        self::assertNull($this->directory->lookup('/user/nonexistent'));
    }

    #[Test]
    public function removeDeletesEntry(): void
    {
        $this->directory->register('/user/orders', 3);
        $this->directory->remove('/user/orders');

        self::assertNull($this->directory->lookup('/user/orders'));
    }

    #[Test]
    public function hasChecksExistence(): void
    {
        self::assertFalse($this->directory->has('/user/orders'));

        $this->directory->register('/user/orders', 3);

        self::assertTrue($this->directory->has('/user/orders'));
    }

    #[Test]
    public function registerOverwrites(): void
    {
        $this->directory->register('/user/orders', 3);
        $this->directory->register('/user/orders', 7);

        self::assertSame(7, $this->directory->lookup('/user/orders'));
    }

    protected function setUp(): void
    {
        $this->table = new Table(1024);
        $this->table->column('worker_id', Table::TYPE_INT);
        $this->table->create();
        $this->directory = new SwooleTableDirectory($this->table);
    }
}
