<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Doctrine\Orm\EntityBehavior;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Doctrine\Orm\Behavior\ActorSystemSpawner;
use Monadial\Nexus\Doctrine\Orm\Behavior\EntityEffect;
use Monadial\Nexus\Doctrine\Orm\Behavior\EntityRefFactory;
use Monadial\Nexus\Doctrine\Orm\Behavior\ReplayPolicy\CreateIfMissing;
use Monadial\Nexus\Doctrine\Orm\Pool\DefaultEntityManagerFactory;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use Monadial\Nexus\Tests\Integration\Doctrine\Orm\EntityBehavior\Fixture\Counter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EntityRefFactoryTest extends TestCase
{
    /**
     * @psalm-suppress InvalidArgument CreateIfMissing<Counter> is assignment-compatible at runtime; Psalm treats generic as invariant
     */
    #[Test]
    public function ofReturnsSameRefForSameId(): void
    {
        $dbPath = tempnam(sys_get_temp_dir(), 'nexus-eb-factory-');
        self::assertIsString($dbPath);

        try {
            $config = ORMSetup::createAttributeMetadataConfig(paths: [__DIR__ . '/Fixture']);
            $config->enableNativeLazyObjects(true);
            $connParams = ['driver' => 'pdo_sqlite', 'path' => $dbPath];

            // Schema bootstrap
            $bootstrap = DriverManager::getConnection($connParams);
            $bootstrapEm = (new DefaultEntityManagerFactory($config))->create($bootstrap);
            (new SchemaTool($bootstrapEm))->createSchema([$bootstrapEm->getClassMetadata(Counter::class)]);
            $bootstrapEm->close();
            $bootstrap->close();

            $runtime = new FiberRuntime();
            $system = ActorSystem::create('test', $runtime);
            $emFactory = new DefaultEntityManagerFactory($config);

            $factory = EntityRefFactory::for(new ActorSystemSpawner($system), Counter::class)
                ->using($emFactory)
                ->withConnectionSource(static fn(): Connection => DriverManager::getConnection($connParams))
                ->withReplayPolicy(new CreateIfMissing(static fn(string $id): Counter => new Counter($id)))
                ->handle(static fn(ActorContext $ctx, object $msg, Counter $c): EntityEffect => EntityEffect::same())
                ->build();

            $a = $factory->of('shared');
            $b = $factory->of('shared');
            $c = $factory->of('other');

            self::assertSame($a, $b);
            self::assertNotSame($a, $c);

            $runtime->scheduleOnce(Duration::millis(100), static fn() => $system->shutdown(Duration::seconds(1)));
            $system->run();
        } finally {
            @unlink($dbPath);
        }
    }
}
