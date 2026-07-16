<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Tests\Unit\Behavior;

use BadMethodCallException;
use Doctrine\DBAL\Connection;
use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Doctrine\Orm\Behavior\ActorSpawner;
use Monadial\Nexus\Doctrine\Orm\Behavior\EntityEffect;
use Monadial\Nexus\Doctrine\Orm\Behavior\EntityRefFactory;
use Monadial\Nexus\Doctrine\Orm\Pool\EntityManagerFactory;
use Monadial\Nexus\Runtime\Async\Future;
use Monadial\Nexus\Runtime\Duration;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(EntityRefFactory::class)]
final class EntityRefFactoryEvictionTest extends TestCase
{
    #[Test]
    public function deadCachedRefIsEvictedAndRespawned(): void
    {
        /** @var list<StubActorRef> $spawned */
        $spawned = [];

        $spawner = new class ($spawned) implements ActorSpawner {
            /** @param list<StubActorRef> $spawned */
            public function __construct(private array &$spawned) {}

            #[Override]
            public function spawn(Props $props, string $name): ActorRef
            {
                $ref = new StubActorRef();
                $this->spawned[] = $ref;

                return $ref;
            }
        };

        $emFactory = $this->createStub(EntityManagerFactory::class);
        $conn = $this->createStub(Connection::class);

        $factory = EntityRefFactory::for($spawner, stdClass::class)
            ->using($emFactory)
            ->withConnectionSource(static fn(): Connection => $conn)
            ->handle(static fn($ctx, object $msg, object $entity): EntityEffect => EntityEffect::same())
            ->build();

        $refA = $factory->of('k1');
        $refB = $factory->of('k1');
        self::assertSame($refA, $refB, 'second call returns cached ref');
        self::assertCount(1, $spawned, 'spawner called once while ref alive');

        // Kill refA so isAlive() returns false
        $spawned[0]->alive = false;

        $refC = $factory->of('k1');
        self::assertNotSame($refA, $refC, 'dead ref is evicted, new ref spawned');
        self::assertTrue($refC->isAlive(), 'freshly spawned ref is alive');
        self::assertCount(2, $spawned, 'spawner called a second time after eviction');
    }
}

final class StubActorRef implements ActorRef
{
    public bool $alive = true;

    #[Override]
    public function tell(object $message): void
    {
        // no-op stub
    }

    #[Override]
    public function ask(object $message, Duration $timeout): Future
    {
        throw new BadMethodCallException('not used in this test');
    }

    #[Override]
    public function path(): ActorPath
    {
        throw new BadMethodCallException('not used in this test');
    }

    #[Override]
    public function isAlive(): bool
    {
        return $this->alive;
    }
}
