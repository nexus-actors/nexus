<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Doctrine\Orm\Fiber;

use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Monadial\Nexus\Doctrine\Orm\DoctrineEmPool;
use Monadial\Nexus\Doctrine\Orm\Pool\EmPoolConfig;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Tests\Integration\Doctrine\Orm\Fiber\Fixture\Item;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EntityManagerPoolFiberTest extends TestCase
{
    #[Test]
    public function persistAndReadBack(): void
    {
        $config = ORMSetup::createAttributeMetadataConfig(paths: [__DIR__ . '/Fixture']);
        $config->enableNativeLazyObjects(true);

        $dbPath = tempnam(sys_get_temp_dir(), 'nexus-em-pool-test-');
        self::assertIsString($dbPath);
        $connParams = ['driver' => 'pdo_sqlite', 'path' => $dbPath];

        $pool = DoctrineEmPool::forConfig(
            name: 'items',
            connParams: $connParams,
            ormSetup: $config,
            config: new EmPoolConfig(
                clearOnReturn: true,
                max: 2,
                minIdle: 0,
            ),
        );

        $em = $pool->take();
        (new SchemaTool($em))->createSchema([$em->getClassMetadata(Item::class)]);
        $item = new Item('keyboard');
        $em->persist($item);
        $em->flush();
        $id = $item->id;
        self::assertNotNull($id);
        $pool->release($em);

        $emRead = $pool->take();
        $reloaded = $emRead->find(Item::class, $id);
        self::assertNotNull($reloaded);
        self::assertSame('keyboard', $reloaded->name);
        $pool->release($emRead);

        $pool->close(Duration::seconds(1));
        @unlink($dbPath);
    }
}
