<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Doctrine\Orm\EntityBehavior;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Monadial\Nexus\Doctrine\Orm\Pool\DefaultEntityManagerFactory;
use Monadial\Nexus\Tests\Integration\Doctrine\Orm\EntityBehavior\Fixture\VersionedItem;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OptimisticLockTest extends TestCase
{
    #[Test]
    public function versionMismatchTriggersOptimisticLockException(): void
    {
        $dbPath = tempnam(sys_get_temp_dir(), 'nexus-eb-version-');
        self::assertIsString($dbPath);

        try {
            $config = ORMSetup::createAttributeMetadataConfig(paths: [__DIR__ . '/Fixture']);
            $config->enableNativeLazyObjects(true);
            $connParams = ['driver' => 'pdo_sqlite', 'path' => $dbPath];

            // Bootstrap schema + seed entity
            $bootstrap = DriverManager::getConnection($connParams);
            $bootstrapEm = (new DefaultEntityManagerFactory($config))->create($bootstrap);
            (new SchemaTool($bootstrapEm))->createSchema([$bootstrapEm->getClassMetadata(VersionedItem::class)]);
            $bootstrapEm->persist(new VersionedItem('v-1'));
            $bootstrapEm->flush();
            $bootstrapEm->close();
            $bootstrap->close();

            // EM-A and EM-B both load the same entity with version=1
            $connA = DriverManager::getConnection($connParams);
            $emA = (new DefaultEntityManagerFactory($config))->create($connA);
            $itemA = $emA->find(VersionedItem::class, 'v-1');

            $connB = DriverManager::getConnection($connParams);
            $emB = (new DefaultEntityManagerFactory($config))->create($connB);
            $itemB = $emB->find(VersionedItem::class, 'v-1');

            self::assertNotNull($itemA);
            self::assertNotNull($itemB);
            self::assertSame(1, $itemA->version);
            self::assertSame(1, $itemB->version);

            // EM-A wins the race: flush increments version to 2 in the DB
            $itemA->count = 5;
            $emA->flush();
            /** @psalm-suppress DocblockTypeContradiction Doctrine mutates $version at runtime; Psalm infers literal 1 from the initializer */
            self::assertSame(2, $itemA->version);

            // EM-B still tracks version=1 — flush must raise OptimisticLockException
            $itemB->count = 99;
            $this->expectException(OptimisticLockException::class);
            $emB->flush();
        } finally {
            @unlink($dbPath);
        }
    }
}
