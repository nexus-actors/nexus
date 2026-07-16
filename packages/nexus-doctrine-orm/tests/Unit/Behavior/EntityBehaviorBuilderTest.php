<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Tests\Unit\Behavior;

use Doctrine\DBAL\Connection;
use LogicException;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Doctrine\Orm\Behavior\EntityBehaviorBuilder;
use Monadial\Nexus\Doctrine\Orm\Behavior\EntityEffect;
use Monadial\Nexus\Doctrine\Orm\Behavior\ReplayPolicy\CreateIfMissing;
use Monadial\Nexus\Doctrine\Orm\Behavior\ReplayPolicy\FailIfMissing;
use Monadial\Nexus\Doctrine\Orm\Pool\EntityManagerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(EntityBehaviorBuilder::class)]
final class EntityBehaviorBuilderTest extends TestCase
{
    #[Test]
    public function requiresEntityManagerFactoryBeforeBuild(): void
    {
        $builder = $this->builder();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('EntityManagerFactory required');
        $builder->toBehavior();
    }

    #[Test]
    public function requiresConnectionSourceBeforeBuild(): void
    {
        $builder = $this->builder()
            ->withEntityManagerFactory($this->createStub(EntityManagerFactory::class));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Connection source required');
        $builder->toBehavior();
    }

    #[Test]
    public function toBehaviorReturnsBehaviorWhenFullyConfigured(): void
    {
        $factory = $this->createStub(EntityManagerFactory::class);
        $conn = $this->createStub(Connection::class);

        $builder = $this->builder()
            ->withEntityManagerFactory($factory)
            ->withConnectionSource(static fn(): Connection => $conn);

        self::assertInstanceOf(Behavior::class, $builder->toBehavior());
    }

    #[Test]
    public function fluentSettersReturnNewInstance(): void
    {
        $base = $this->builder();
        $emFactory = $this->createStub(EntityManagerFactory::class);
        $policy = new CreateIfMissing(static fn(mixed $id): object => new stdClass());

        $configured = $base
            ->withEntityManagerFactory($emFactory)
            ->withReplayPolicy($policy);

        self::assertNotSame($base, $configured);
        self::assertInstanceOf(FailIfMissing::class, $base->replayPolicy);
        self::assertSame($emFactory, $configured->emFactory);
        self::assertSame($policy, $configured->replayPolicy);
    }

    #[Test]
    public function withDirectConnectionWiresConnectionSource(): void
    {
        $builder = $this->builder()->withDirectConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        self::assertNotNull($builder->connectionSource);
    }

    #[Test]
    public function withConnectionLifecycleStoresBothAcquireAndRelease(): void
    {
        $conn = $this->createStub(Connection::class);
        $acquire = static fn(): Connection => $conn;
        $release = static fn(Connection $c): null => null;

        $builder = $this->builder()->withConnectionLifecycle($acquire, $release);

        self::assertSame($acquire, $builder->connectionSource);
        self::assertSame($release, $builder->connectionRelease);
    }

    #[Test]
    public function withConnectionSourceClearsAnyPriorReleaseHook(): void
    {
        $conn = $this->createStub(Connection::class);
        $acquire = static fn(): Connection => $conn;
        $release = static fn(Connection $c): null => null;
        $dedicated = static fn(): Connection => $conn;

        $builder = $this->builder()
            ->withConnectionLifecycle($acquire, $release)
            ->withConnectionSource($dedicated);

        self::assertSame($dedicated, $builder->connectionSource);
        self::assertNull($builder->connectionRelease);
    }

    private function builder(): EntityBehaviorBuilder
    {
        return new EntityBehaviorBuilder(
            entityClass: stdClass::class,
            id: 'k',
            commandHandler: static fn($ctx, object $msg, object $entity): EntityEffect => EntityEffect::same(),
        );
    }
}
