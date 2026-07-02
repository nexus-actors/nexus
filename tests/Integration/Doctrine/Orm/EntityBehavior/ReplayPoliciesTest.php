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
use Monadial\Nexus\Core\Exception\ActorInitializationException;
use Monadial\Nexus\Doctrine\Orm\Behavior\EntityBehavior;
use Monadial\Nexus\Doctrine\Orm\Behavior\EntityEffect;
use Monadial\Nexus\Doctrine\Orm\Behavior\ReplayPolicy\CreateIfMissing;
use Monadial\Nexus\Doctrine\Orm\Behavior\ReplayPolicy\FailIfMissing;
use Monadial\Nexus\Doctrine\Orm\Behavior\ReplayPolicy\OnDemand;
use Monadial\Nexus\Doctrine\Orm\Pool\DefaultEntityManagerFactory;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use Monadial\Nexus\Tests\Integration\Doctrine\Orm\EntityBehavior\Fixture\Add;
use Monadial\Nexus\Tests\Integration\Doctrine\Orm\EntityBehavior\Fixture\Counter;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class ReplayPoliciesTest extends TestCase
{
    #[Test]
    public function failIfMissingPreventsActorFromHandlingCommands(): void
    {
        $dbPath = tempnam(sys_get_temp_dir(), 'nexus-eb-fail-');
        self::assertIsString($dbPath);

        try {
            $config = ORMSetup::createAttributeMetadataConfig(paths: [__DIR__ . '/Fixture']);
            $config->enableNativeLazyObjects(true);
            $connParams = ['driver' => 'pdo_sqlite', 'path' => $dbPath];

            // Bootstrap: create schema only — no row inserted
            $bootstrap = DriverManager::getConnection($connParams);
            $bootstrapEm = (new DefaultEntityManagerFactory($config))->create($bootstrap);
            (new SchemaTool($bootstrapEm))->createSchema([$bootstrapEm->getClassMetadata(Counter::class)]);
            $bootstrapEm->close();
            $bootstrap->close();

            $runtime = new FiberRuntime();
            $system = ActorSystem::create('test', $runtime);
            $emFactory = new DefaultEntityManagerFactory($config);

            $behavior = EntityBehavior::create(
                entityClass: Counter::class,
                id: 'c-fail',
                commandHandler: static fn(ActorContext $ctx, object $msg, Counter $c): EntityEffect => match (true) {
                    $msg instanceof Add => $c->tryAdd($msg->delta)
                        ? EntityEffect::persist()
                        : EntityEffect::same(),
                    default => EntityEffect::same(),
                },
            )
                ->withEntityManagerFactory($emFactory)
                ->withReplayPolicy(new FailIfMissing())
                ->withConnectionSource(static fn(): Connection => DriverManager::getConnection($connParams))
                ->toBehavior();

            $initFailed = false;

            try {
                $ref = $system->spawn(Props::fromBehavior($behavior), 'counter-fail');
                $ref->tell(new Add(5));
            } catch (ActorInitializationException) {
                $initFailed = true;
            }

            // The actor failed to start (FailIfMissing threw); no row was ever persisted
            self::assertTrue($initFailed);

            $verifyConn = DriverManager::getConnection($connParams);
            $count = (int) $verifyConn->fetchOne('SELECT COUNT(*) FROM counters');
            $verifyConn->close();

            self::assertSame(0, $count);
        } finally {
            @unlink($dbPath);
        }
    }

    #[Test]
    public function createIfMissingInvokesFactoryOnce(): void
    {
        $dbPath = tempnam(sys_get_temp_dir(), 'nexus-eb-create-');
        self::assertIsString($dbPath);

        try {
            $config = ORMSetup::createAttributeMetadataConfig(paths: [__DIR__ . '/Fixture']);
            $config->enableNativeLazyObjects(true);
            $connParams = ['driver' => 'pdo_sqlite', 'path' => $dbPath];

            // Bootstrap: create schema only — no row inserted
            $bootstrap = DriverManager::getConnection($connParams);
            $bootstrapEm = (new DefaultEntityManagerFactory($config))->create($bootstrap);
            (new SchemaTool($bootstrapEm))->createSchema([$bootstrapEm->getClassMetadata(Counter::class)]);
            $bootstrapEm->close();
            $bootstrap->close();

            $runtime = new FiberRuntime();
            $system = ActorSystem::create('test', $runtime);
            $emFactory = new DefaultEntityManagerFactory($config);

            $factoryCallCount = 0;

            $behavior = EntityBehavior::create(
                entityClass: Counter::class,
                id: 'c-create',
                commandHandler: static fn(ActorContext $ctx, object $msg, Counter $c): EntityEffect => match (true) {
                    $msg instanceof Add => $c->tryAdd($msg->delta)
                        ? EntityEffect::persist()
                        : EntityEffect::same(),
                    default => EntityEffect::same(),
                },
            )
                ->withEntityManagerFactory($emFactory)
                ->withReplayPolicy(
                    new CreateIfMissing(
                        static function (string $id) use (&$factoryCallCount): Counter {
                            $factoryCallCount++;

                            return new Counter($id);
                        },
                    ),
                )
                ->withConnectionSource(static fn(): Connection => DriverManager::getConnection($connParams))
                ->toBehavior();

            $ref = $system->spawn(Props::fromBehavior($behavior), 'counter-create');
            $ref->tell(new Add(5));

            $runtime->scheduleOnce(Duration::millis(500), static fn() => $system->shutdown(Duration::seconds(1)));
            $system->run();

            // Verify the row was created and the delta was applied
            $verifyConn = DriverManager::getConnection($connParams);
            $verifyEm = (new DefaultEntityManagerFactory($config))->create($verifyConn);
            $stored = $verifyEm->find(Counter::class, 'c-create');

            self::assertNotNull($stored);
            self::assertSame(5, $stored->value);
            $verifyEm->close();
            $verifyConn->close();

            // Factory was invoked exactly once (on PreStart)
            self::assertSame(1, $factoryCallCount);
        } finally {
            @unlink($dbPath);
        }
    }

    #[Test]
    public function onDemandDefersLoadUntilFirstCommand(): void
    {
        $dbPath = tempnam(sys_get_temp_dir(), 'nexus-eb-ondemand-');
        self::assertIsString($dbPath);

        try {
            $config = ORMSetup::createAttributeMetadataConfig(paths: [__DIR__ . '/Fixture']);
            $config->enableNativeLazyObjects(true);
            $connParams = ['driver' => 'pdo_sqlite', 'path' => $dbPath];

            // Bootstrap: create schema and pre-insert a row with value=100
            $bootstrap = DriverManager::getConnection($connParams);
            $bootstrapEm = (new DefaultEntityManagerFactory($config))->create($bootstrap);
            (new SchemaTool($bootstrapEm))->createSchema([$bootstrapEm->getClassMetadata(Counter::class)]);
            $bootstrapEm->persist(new Counter('c-ondemand', 100));
            $bootstrapEm->flush();
            $bootstrapEm->close();
            $bootstrap->close();

            $runtime = new FiberRuntime();
            $system = ActorSystem::create('test', $runtime);
            $emFactory = new DefaultEntityManagerFactory($config);

            $behavior = EntityBehavior::create(
                entityClass: Counter::class,
                id: 'c-ondemand',
                commandHandler: static fn(ActorContext $ctx, object $msg, Counter $c): EntityEffect => match (true) {
                    $msg instanceof Add => $c->tryAdd($msg->delta)
                        ? EntityEffect::persist()
                        : EntityEffect::same(),
                    default => EntityEffect::same(),
                },
            )
                ->withEntityManagerFactory($emFactory)
                ->withReplayPolicy(new OnDemand())
                ->withConnectionSource(static fn(): Connection => DriverManager::getConnection($connParams))
                ->toBehavior();

            $ref = $system->spawn(Props::fromBehavior($behavior), 'counter-ondemand');
            $ref->tell(new Add(50));

            $runtime->scheduleOnce(Duration::millis(500), static fn() => $system->shutdown(Duration::seconds(1)));
            $system->run();

            // Entity was loaded on first command; value should be 100 + 50 = 150
            $verifyConn = DriverManager::getConnection($connParams);
            $verifyEm = (new DefaultEntityManagerFactory($config))->create($verifyConn);
            $stored = $verifyEm->find(Counter::class, 'c-ondemand');

            self::assertNotNull($stored);
            self::assertSame(150, $stored->value);

            $verifyEm->close();
            $verifyConn->close();
        } finally {
            @unlink($dbPath);
        }
    }
}
