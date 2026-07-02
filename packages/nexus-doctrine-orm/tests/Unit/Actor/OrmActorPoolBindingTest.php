<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Tests\Unit\Actor;

use Doctrine\ORM\ORMSetup;
use Monadial\Nexus\Doctrine\Dbal\Actor\ActorPoolBinding;
use Monadial\Nexus\Doctrine\Dbal\DoctrinePool;
use Monadial\Nexus\Doctrine\Dbal\Pool\PoolConfig;
use Monadial\Nexus\Doctrine\Orm\Actor\OrmActorPoolBinding;
use Monadial\Nexus\Doctrine\Orm\DoctrineEmPool;
use Monadial\Nexus\Doctrine\Orm\Pool\EmPoolConfig;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;

#[CoversClass(OrmActorPoolBinding::class)]
final class OrmActorPoolBindingTest extends TestCase
{
    #[Test]
    public function carriesBothPools(): void
    {
        $ormConfig = ORMSetup::createAttributeMetadataConfig(paths: []);
        $ormConfig->enableNativeLazyObjects(true);

        $conn = DoctrinePool::fromParams(
            name: 'orders-conn',
            connParams: ['driver' => 'pdo_sqlite', 'memory' => true],
            config: new PoolConfig(max: 1, minIdle: 0),
        );
        $em = DoctrineEmPool::forConfig(
            name: 'orders-em',
            connParams: ['driver' => 'pdo_sqlite', 'memory' => true],
            ormSetup: $ormConfig,
            config: new EmPoolConfig(max: 1, minIdle: 0),
        );

        $binding = new OrmActorPoolBinding(new ActorPoolBinding($conn), $em);

        self::assertSame($conn, $binding->base->connPool);
        self::assertSame($em, $binding->emPool);
    }

    #[Override]
    protected function setUp(): void
    {
        if (extension_loaded('swoole') && Coroutine::getCid() === -1) {
            self::markTestSkipped('SwooleChannel requires coroutine context');
        }
    }
}
