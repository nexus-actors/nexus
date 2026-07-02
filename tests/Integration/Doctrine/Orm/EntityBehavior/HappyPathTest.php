<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Doctrine\Orm\EntityBehavior;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Doctrine\Orm\Behavior\EntityBehavior;
use Monadial\Nexus\Doctrine\Orm\Behavior\EntityEffect;
use Monadial\Nexus\Doctrine\Orm\Behavior\ReplayPolicy\CreateIfMissing;
use Monadial\Nexus\Doctrine\Orm\Pool\DefaultEntityManagerFactory;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use Monadial\Nexus\Tests\Integration\Doctrine\Orm\EntityBehavior\Fixture\Add;
use Monadial\Nexus\Tests\Integration\Doctrine\Orm\EntityBehavior\Fixture\Counter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HappyPathTest extends TestCase
{
    /**
     * @psalm-suppress InvalidArgument CreateIfMissing<Counter> is assignment-compatible at runtime; Psalm treats generic as invariant
     */
    #[Test]
    public function counterAccumulates(): void
    {
        $dbPath = tempnam(sys_get_temp_dir(), 'nexus-eb-happy-');
        self::assertIsString($dbPath);

        try {
            $config = ORMSetup::createAttributeMetadataConfig(paths: [__DIR__ . '/Fixture']);
            $config->enableNativeLazyObjects(true);
            $connParams = ['driver' => 'pdo_sqlite', 'path' => $dbPath];

            // Bootstrap: create schema
            $bootstrap = DriverManager::getConnection($connParams);
            $bootstrapEm = (new DefaultEntityManagerFactory($config))->create($bootstrap);
            (new SchemaTool($bootstrapEm))->createSchema([$bootstrapEm->getClassMetadata(Counter::class)]);
            $bootstrapEm->close();
            $bootstrap->close();

            // Run the actor system
            $runtime = new FiberRuntime();
            $system = ActorSystem::create('test', $runtime);
            $emFactory = new DefaultEntityManagerFactory($config);

            $behavior = EntityBehavior::create(
                entityClass: Counter::class,
                id: 'c-1',
                commandHandler: static fn(ActorContext $ctx, object $msg, Counter $c): EntityEffect => match (true) {
                    $msg instanceof Add => $c->tryAdd($msg->delta)
                        ? EntityEffect::persist()
                        : EntityEffect::same(),
                    default => EntityEffect::same(),
                },
            )
                ->withEntityManagerFactory($emFactory)
                ->withReplayPolicy(new CreateIfMissing(static fn(string $id): Counter => new Counter($id)))
                ->withConnectionSource(static fn(): Connection => DriverManager::getConnection($connParams))
                ->toBehavior();

            $ref = $system->spawn(Props::fromBehavior($behavior), 'counter-1');
            $ref->tell(new Add(3));
            $ref->tell(new Add(7));

            $runtime->scheduleOnce(Duration::millis(500), static fn() => $system->shutdown(Duration::seconds(1)));
            $system->run();

            // Verify via fresh EM (after the actor has flushed and shut down)
            $verifyConn = DriverManager::getConnection($connParams);
            $verifyEm = (new DefaultEntityManagerFactory($config))->create($verifyConn);
            $stored = $verifyEm->find(Counter::class, 'c-1');

            self::assertNotNull($stored);
            self::assertSame(10, $stored->value);

            $verifyEm->close();
            $verifyConn->close();
        } finally {
            @unlink($dbPath);
        }
    }
}
