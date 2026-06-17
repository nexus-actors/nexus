<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Tests\Unit;

use Doctrine\ORM\ORMSetup;
use Monadial\Nexus\Doctrine\Orm\DoctrineEmPool;
use Monadial\Nexus\Doctrine\Orm\Pool\EmPoolConfig;
use Monadial\Nexus\Doctrine\Orm\Pool\EntityManagerPool;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;

#[CoversClass(DoctrineEmPool::class)]
final class DoctrineEmPoolTest extends TestCase
{
    #[Test]
    public function forConfigBuildsPool(): void
    {
        $ormConfig = ORMSetup::createAttributeMetadataConfig(paths: []);
        $ormConfig->enableNativeLazyObjects(true);

        $pool = DoctrineEmPool::forConfig(
            name: 'orders',
            connParams: ['driver' => 'pdo_sqlite', 'memory' => true],
            ormSetup: $ormConfig,
            config: new EmPoolConfig(max: 2, minIdle: 0),
        );

        self::assertInstanceOf(EntityManagerPool::class, $pool);
        $em = $pool->take();
        self::assertTrue($em->isOpen());
        $pool->release($em);
    }

    #[Override]
    protected function setUp(): void
    {
        if (extension_loaded('swoole') && Coroutine::getCid() === -1) {
            self::markTestSkipped('SwooleChannel requires coroutine context; covered separately under unit-swoole');
        }
    }
}
