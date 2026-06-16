<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Tests\Unit;

use Monadial\Nexus\Doctrine\Dbal\DoctrinePool;
use Monadial\Nexus\Doctrine\Dbal\Pool\ConnectionPool;
use Monadial\Nexus\Doctrine\Dbal\Pool\PoolConfig;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine as SwooleCoroutine;

#[CoversClass(DoctrinePool::class)]
final class DoctrinePoolTest extends TestCase
{
    #[Test]
    public function fromParamsBuildsAPool(): void
    {
        $pool = DoctrinePool::fromParams(
            name: 'orders',
            connParams: ['driver' => 'pdo_sqlite', 'memory' => true],
            config: new PoolConfig(max: 2, minIdle: 0),
        );

        self::assertInstanceOf(ConnectionPool::class, $pool);
        $conn = $pool->take();
        $pool->release($conn);
    }

    #[Override]
    protected function setUp(): void
    {
        // SwooleChannel::pop() requires a coroutine context. Skip in the
        // full-stack php container (Swoole loaded, no active coroutine) so
        // the unit testsuite does not segfault. The test runs green under
        // php-fiber where extension_loaded('swoole') is false.
        if (extension_loaded('swoole')) {
            /** @psalm-suppress UndefinedClass */
            if (SwooleCoroutine::getCid() === -1) {
                $this->markTestSkipped('SwooleChannel requires a coroutine context; run under php-fiber.');
            }
        }
    }
}
