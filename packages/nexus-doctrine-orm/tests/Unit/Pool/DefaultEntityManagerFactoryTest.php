<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Tests\Unit\Pool;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Monadial\Nexus\Doctrine\Orm\Pool\DefaultEntityManagerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DefaultEntityManagerFactory::class)]
final class DefaultEntityManagerFactoryTest extends TestCase
{
    #[Test]
    public function createBindsEmToProvidedConnection(): void
    {
        $config = ORMSetup::createAttributeMetadataConfig(paths: []);
        $config->enableNativeLazyObjects(true);
        $factory = new DefaultEntityManagerFactory($config);
        $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        $em = $factory->create($conn);

        self::assertInstanceOf(EntityManagerInterface::class, $em);
        self::assertSame($conn, $em->getConnection());
        $em->close();
    }
}
