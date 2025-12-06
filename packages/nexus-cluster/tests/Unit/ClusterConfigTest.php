<?php
declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tests\Unit;

use InvalidArgumentException;
use Monadial\Nexus\Cluster\ClusterConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ClusterConfig::class)]
final class ClusterConfigTest extends TestCase
{
    #[Test]
    public function withWorkersCreatesConfigWithGivenCount(): void
    {
        $config = ClusterConfig::withWorkers(16);

        self::assertSame(16, $config->workerCount);
    }

    #[Test]
    public function defaultTableSizeIs65536(): void
    {
        $config = ClusterConfig::withWorkers(4);

        self::assertSame(65536, $config->tableSize);
    }

    #[Test]
    public function customTableSize(): void
    {
        $config = ClusterConfig::withWorkers(4, tableSize: 1024);

        self::assertSame(1024, $config->tableSize);
    }

    #[Test]
    public function socketPathDefaultsToTmpDirectory(): void
    {
        $config = ClusterConfig::withWorkers(4);

        self::assertStringStartsWith('/tmp/nexus-cluster-', $config->socketDir);
    }

    #[Test]
    public function customSocketDir(): void
    {
        $config = ClusterConfig::withWorkers(4, socketDir: '/var/run/nexus');

        self::assertSame('/var/run/nexus', $config->socketDir);
    }

    #[Test]
    public function workerCountMustBePositive(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ClusterConfig::withWorkers(0);
    }
}
