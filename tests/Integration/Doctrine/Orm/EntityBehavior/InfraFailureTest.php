<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Doctrine\Orm\EntityBehavior;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Doctrine\Orm\Behavior\EntityBehavior;
use Monadial\Nexus\Doctrine\Orm\Behavior\EntityEffect;
use Monadial\Nexus\Doctrine\Orm\Behavior\ReplayPolicy\CreateIfMissing;
use Monadial\Nexus\Doctrine\Orm\Pool\DefaultEntityManagerFactory;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use Monadial\Nexus\Tests\Integration\Doctrine\Orm\EntityBehavior\Fixture\Add;
use Monadial\Nexus\Tests\Integration\Doctrine\Orm\EntityBehavior\Fixture\Counter;
use Monadial\Nexus\Tests\Integration\Doctrine\Orm\EntityBehavior\Fixture\FlushFailed;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Resilience contract: when a command's flush hits an infra failure the runner
 * must (a) fire the `thenReplyOnFailure` targets so the caller does not hang on
 * its ask timeout, and (b) stop the actor so its stale EntityManager / dead
 * Connection are torn down and `of()` respawns a fresh actor next time.
 */
final class InfraFailureTest extends TestCase
{
    /**
     * @psalm-suppress InvalidArgument CreateIfMissing<Counter> is assignment-compatible at runtime; Psalm treats generic as invariant
     */
    #[Test]
    public function flushFailureRepliesToFailureTargetAndStopsActor(): void
    {
        $dbPath = tempnam(sys_get_temp_dir(), 'nexus-eb-infra-');
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

            $runtime = new FiberRuntime();
            $system = ActorSystem::create('test', $runtime);

            // Dedicated connection via a close-spy so we can prove the failed
            // actor released its connection on teardown (no zombie).
            $spy = new ConnectionCloseSpy($connParams, DriverManager::getConnection($connParams)->getDriver());

            // Real EM for find/persist, but flush always throws.
            $emFactory = new FlushFailingEntityManagerFactory(new DefaultEntityManagerFactory($config));

            // Probe actor captures the failure reply.
            $collector = new MessageCollector();
            $probe = $system->spawn(
                Props::fromBehavior(
                    Behavior::receive(
                        static function (ActorContext $ctx, object $msg) use ($collector): Behavior {
                            $collector->record($msg);

                            return Behavior::same();
                        },
                    ),
                ),
                'probe',
            );

            $behavior = EntityBehavior::create(
                entityClass: Counter::class,
                id: 'c-1',
                commandHandler: static fn(ActorContext $ctx, object $msg, Counter $c): EntityEffect => $msg instanceof Add
                    ? $c->tryAdd($msg->delta)
                        ? EntityEffect::persist()->thenReplyOnFailure(
                            $probe,
                            static fn(Throwable $e): object => new FlushFailed($e->getMessage()),
                        )
                        : EntityEffect::same()
                    : EntityEffect::same(),
            )
                ->withEntityManagerFactory($emFactory)
                ->withReplayPolicy(new CreateIfMissing(static fn(string $id): Counter => new Counter($id)))
                ->withConnectionSource(static fn(): Connection => $spy)
                ->toBehavior();

            $ref = $system->spawn(Props::fromBehavior($behavior), 'counter-1');
            $ref->tell(new Add(3));

            /** @var bool|null $aliveAfterFailure */
            $aliveAfterFailure = null;
            $runtime->scheduleOnce(
                Duration::millis(300),
                static function () use ($ref, &$aliveAfterFailure): void {
                    $aliveAfterFailure = $ref->isAlive();
                },
            );

            $runtime->scheduleOnce(Duration::millis(500), static fn() => $system->shutdown(Duration::seconds(1)));
            $system->run();

            self::assertCount(1, $collector->messages, 'failure target received exactly one reply — no silent hang');
            self::assertInstanceOf(FlushFailed::class, $collector->messages[0]);
            self::assertSame('flush failed: connection lost', $collector->messages[0]->reason);
            self::assertFalse(
                $aliveAfterFailure,
                'actor stops itself after an infra failure so of() respawns a fresh one',
            );
            self::assertSame(1, $spy->closeCalls, 'stopped actor releases its dedicated connection — no zombie');
        } finally {
            @unlink($dbPath);
        }
    }
}
