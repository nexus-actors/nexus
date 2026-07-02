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
use Monadial\Nexus\Tests\Integration\Doctrine\Orm\EntityBehavior\Fixture\Add;
use Monadial\Nexus\Tests\Integration\Doctrine\Orm\EntityBehavior\Fixture\Counter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PassivationTest extends TestCase
{
    /**
     * @psalm-suppress InvalidArgument CreateIfMissing<Counter> is assignment-compatible at runtime; Psalm treats generic as invariant
     */
    #[Test]
    public function entityActorPassivatesAndRehydratesFromDb(): void
    {
        $dbPath = tempnam(sys_get_temp_dir(), 'nexus-eb-passivation-');
        self::assertIsString($dbPath);

        try {
            // Schema bootstrap (same pattern as HappyPathTest)
            $config = ORMSetup::createAttributeMetadataConfig(paths: [__DIR__ . '/Fixture']);
            $config->enableNativeLazyObjects(true);
            $connParams = ['driver' => 'pdo_sqlite', 'path' => $dbPath];

            $bootstrap = DriverManager::getConnection($connParams);
            $bootstrapEm = (new DefaultEntityManagerFactory($config))->create($bootstrap);
            (new SchemaTool($bootstrapEm))->createSchema([$bootstrapEm->getClassMetadata(Counter::class)]);
            $bootstrapEm->close();
            $bootstrap->close();

            $runtime = new FiberRuntime();
            $system = ActorSystem::create('test', $runtime);
            $emFactory = new DefaultEntityManagerFactory($config);

            $commandHandler = static fn(ActorContext $ctx, object $msg, Counter $c): EntityEffect => match (true) {
                $msg instanceof Add => $c->tryAdd($msg->delta)
                    ? EntityEffect::persist()
                    : EntityEffect::same(),
                default => EntityEffect::same(),
            };

            $factory = EntityRefFactory::for(new ActorSystemSpawner($system), Counter::class)
                ->using($emFactory)
                ->withConnectionSource(static fn(): Connection => DriverManager::getConnection($connParams))
                ->withReplayPolicy(new CreateIfMissing(static fn(string $id): Counter => new Counter($id)))
                ->withReceiveTimeout(Duration::millis(300))
                ->handle($commandHandler)
                ->build();

            /** @var \Monadial\Nexus\Core\Actor\ActorRef|null $firstRef */
            $firstRef = null;
            /** @var bool|null $aliveAtT200 */
            $aliveAtT200 = null;
            /** @var bool|null $aliveAtT500 */
            $aliveAtT500 = null;
            /** @var \Monadial\Nexus\Core\Actor\ActorRef|null $secondRef */
            $secondRef = null;

            // 50ms — first command, capture the ref
            $runtime->scheduleOnce(
                Duration::millis(50),
                static function () use ($factory, &$firstRef): void {
                    $firstRef = $factory->of('c-1');
                    $firstRef->tell(new Add(3));
                },
            );

            // 200ms — assert still alive (well within the 300ms idle timeout)
            $runtime->scheduleOnce(
                Duration::millis(200),
                static function () use (&$firstRef, &$aliveAtT200): void {
                    if ($firstRef !== null) {
                        $aliveAtT200 = $firstRef->isAlive();
                    }
                },
            );

            // 500ms — well past first-msg-at-50ms + 300ms timeout; actor should have passivated
            $runtime->scheduleOnce(
                Duration::millis(500),
                static function () use (&$firstRef, &$aliveAtT500): void {
                    if ($firstRef !== null) {
                        $aliveAtT500 = $firstRef->isAlive();
                    }
                },
            );

            // 600ms — second command via factory; should spawn a fresh actor that rehydrates from DB
            $runtime->scheduleOnce(
                Duration::millis(600),
                static function () use ($factory, &$secondRef): void {
                    $secondRef = $factory->of('c-1');
                    $secondRef->tell(new Add(7));
                },
            );

            // 1000ms — shutdown
            $runtime->scheduleOnce(
                Duration::millis(1000),
                static fn() => $system->shutdown(Duration::seconds(1)),
            );

            $system->run();

            // Liveness assertions
            self::assertTrue($aliveAtT200, 'first actor should still be alive at 200ms (within 300ms timeout)');
            self::assertFalse($aliveAtT500, 'first actor should have passivated by 500ms (well past 50+300ms)');
            self::assertNotSame($firstRef, $secondRef, 'second command should spawn a fresh actor');

            // Final DB state — both commands persisted: 3 + 7 = 10
            $verifyConn = DriverManager::getConnection($connParams);
            $verifyEm = (new DefaultEntityManagerFactory($config))->create($verifyConn);
            $stored = $verifyEm->find(Counter::class, 'c-1');

            self::assertNotNull($stored);
            self::assertSame(10, $stored->value, 'rehydrated actor should reload from DB then add 7 on top of 3');

            $verifyEm->close();
            $verifyConn->close();
        } finally {
            @unlink($dbPath);
        }
    }
}
