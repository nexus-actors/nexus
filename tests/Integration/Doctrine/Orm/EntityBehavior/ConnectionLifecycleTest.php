<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Doctrine\Orm\EntityBehavior;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Message\PoisonPill;
use Monadial\Nexus\Doctrine\Orm\Behavior\EntityBehavior;
use Monadial\Nexus\Doctrine\Orm\Behavior\EntityEffect;
use Monadial\Nexus\Doctrine\Orm\Behavior\ReplayPolicy\CreateIfMissing;
use Monadial\Nexus\Doctrine\Orm\Pool\DefaultEntityManagerFactory;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use Monadial\Nexus\Tests\Integration\Doctrine\Orm\EntityBehavior\Fixture\Counter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end verification of the EntityBehavior connection lifecycle —
 * the path the runner takes on `PostStop` for the two supported modes:
 *
 * - {@see EntityBehavior}::create(...)->withConnectionSource($acquire)
 *     dedicated-connection mode; runner calls $conn->close().
 *
 * - {@see EntityBehavior}::create(...)->withConnectionLifecycle($acquire, $release)
 *     pool-backed mode; runner calls $release($conn) and must NOT close
 *     the connection itself.
 *
 * Both tests spawn one actor, stop it via PoisonPill, and assert what
 * happened to the connection after the actor's PostStop signal fired.
 */
final class ConnectionLifecycleTest extends TestCase
{
    /**
     * @psalm-suppress InvalidArgument CreateIfMissing<Counter> is assignment-compatible at runtime; Psalm treats generic as invariant
     */
    #[Test]
    public function dedicatedModeClosesConnectionOnPostStop(): void
    {
        $dbPath = self::tempDb('conn-dedicated');

        try {
            $connParams = self::bootstrapSchema($dbPath);
            $runtime = new FiberRuntime();
            $system = ActorSystem::create('test', $runtime);

            $spy = self::spy($connParams);

            $behavior = EntityBehavior::create(
                Counter::class,
                'c-1',
                static fn(ActorContext $ctx, object $msg, Counter $c): EntityEffect => EntityEffect::same(),
            )
                ->withEntityManagerFactory(new DefaultEntityManagerFactory(self::ormConfig()))
                ->withReplayPolicy(new CreateIfMissing(static fn(string $id): Counter => new Counter($id)))
                ->withConnectionSource(static fn(): Connection => $spy)
                ->toBehavior();

            $runtime->scheduleOnce(
                Duration::millis(50),
                static function () use ($system, $behavior): void {
                    $ref = $system->spawn(Props::fromBehavior($behavior), 'ledger-dedicated');
                    $ref->tell(new PoisonPill());
                },
            );

            $runtime->scheduleOnce(
                Duration::millis(300),
                static fn() => $system->shutdown(Duration::seconds(1)),
            );

            $system->run();

            self::assertSame(1, $spy->closeCalls, 'dedicated mode closes the connection exactly once on PostStop');
        } finally {
            @unlink($dbPath);
        }
    }

    /**
     * @psalm-suppress InvalidArgument CreateIfMissing<Counter> is assignment-compatible at runtime; Psalm treats generic as invariant
     */
    #[Test]
    public function poolBackedModeInvokesReleaseAndKeepsConnectionOpen(): void
    {
        $dbPath = self::tempDb('conn-lifecycle');

        try {
            $connParams = self::bootstrapSchema($dbPath);
            $runtime = new FiberRuntime();
            $system = ActorSystem::create('test', $runtime);

            // "Pool" stub: hand out one shared connection; release just
            // records — it must NOT close the connection, that's the contract
            // a real pool relies on.
            $shared = self::spy($connParams);
            $acquireCalls = 0;
            $releaseCalls = 0;
            /** @var list<Connection> $releasedConns */
            $releasedConns = [];

            $behavior = EntityBehavior::create(
                Counter::class,
                'c-1',
                static fn(ActorContext $ctx, object $msg, Counter $c): EntityEffect => EntityEffect::same(),
            )
                ->withEntityManagerFactory(new DefaultEntityManagerFactory(self::ormConfig()))
                ->withReplayPolicy(new CreateIfMissing(static fn(string $id): Counter => new Counter($id)))
                ->withConnectionLifecycle(
                    acquire: static function () use ($shared, &$acquireCalls): Connection {
                        $acquireCalls++;

                        return $shared;
                    },
                    release: static function (Connection $conn) use (&$releaseCalls, &$releasedConns): void {
                        $releaseCalls++;
                        $releasedConns[] = $conn;
                    },
                )
                ->toBehavior();

            $runtime->scheduleOnce(
                Duration::millis(50),
                static function () use ($system, $behavior): void {
                    $ref = $system->spawn(Props::fromBehavior($behavior), 'ledger-pooled');
                    $ref->tell(new PoisonPill());
                },
            );

            $runtime->scheduleOnce(
                Duration::millis(300),
                static fn() => $system->shutdown(Duration::seconds(1)),
            );

            $system->run();

            self::assertSame(1, $acquireCalls, 'acquire called exactly once');
            self::assertSame(1, $releaseCalls, 'release called exactly once on PostStop');
            self::assertSame($shared, $releasedConns[0], 'release received the same connection');
            self::assertSame(0, $shared->closeCalls, 'pool-backed mode must NOT close the connection');

            $shared->close();
        } finally {
            @unlink($dbPath);
        }
    }

    /** @return non-empty-string */
    private static function tempDb(string $tag): string
    {
        $dbPath = tempnam(sys_get_temp_dir(), "nexus-eb-{$tag}-");
        self::assertIsString($dbPath);
        self::assertNotSame('', $dbPath);

        return $dbPath;
    }

    /**
     * @return array{driver: string, path: string}
     */
    private static function bootstrapSchema(string $dbPath): array
    {
        $connParams = ['driver' => 'pdo_sqlite', 'path' => $dbPath];

        $conn = DriverManager::getConnection($connParams);

        try {
            $em = (new DefaultEntityManagerFactory(self::ormConfig()))->create($conn);

            try {
                (new SchemaTool($em))->createSchema([$em->getClassMetadata(Counter::class)]);
            } finally {
                $em->close();
            }
        } finally {
            $conn->close();
        }

        return $connParams;
    }

    private static function ormConfig(): Configuration
    {
        $config = ORMSetup::createAttributeMetadataConfig(paths: [__DIR__ . '/Fixture']);
        $config->enableNativeLazyObjects(true);

        return $config;
    }

    /**
     * Build a `ConnectionCloseSpy` that shares the underlying driver with
     * a real DBAL connection — every method except close() works exactly
     * like a plain pdo_sqlite connection.
     *
     * @param array{driver: string, path: string} $connParams
     */
    private static function spy(array $connParams): ConnectionCloseSpy
    {
        $real = DriverManager::getConnection($connParams);

        return new ConnectionCloseSpy($connParams, $real->getDriver());
    }
}
