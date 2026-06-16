<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Doctrine\Fiber;

use Monadial\Nexus\Doctrine\Dbal\DoctrinePool;
use Monadial\Nexus\Doctrine\Dbal\Pool\PoolConfig;
use Monadial\Nexus\Runtime\Duration;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConnectionPoolFiberTest extends TestCase
{
    #[Test]
    public function takeReleaseExecutesRealQuery(): void
    {
        $pool = DoctrinePool::fromParams(
            name: 'test',
            connParams: ['driver' => 'pdo_sqlite', 'memory' => true],
            config: new PoolConfig(max: 2, minIdle: 0),
        );

        $value = $pool->withConnection(
            static fn($conn): int => (int) $conn->fetchOne('SELECT 42'),
        );

        self::assertSame(42, $value);
        $pool->close(Duration::seconds(1));
    }
}
