<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Tests\Unit\Behavior;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Doctrine\Orm\Behavior\ActorSpawner;
use Monadial\Nexus\Doctrine\Orm\Behavior\EntityEffect;
use Monadial\Nexus\Doctrine\Orm\Behavior\EntityRefFactory;
use Monadial\Nexus\Doctrine\Orm\Pool\EntityManagerFactory;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Stringable;

#[CoversClass(EntityRefFactory::class)]
final class EntityRefFactoryTest extends TestCase
{
    #[Test]
    public function derivesDeterministicActorName(): void
    {
        self::assertSame('stdClass--42', EntityRefFactory::deriveName(stdClass::class, 42));
        self::assertSame('App.Order--abc', EntityRefFactory::deriveName('App\\Order', 'abc'));
    }

    #[Test]
    public function derivesNameForStringableObjectId(): void
    {
        $id = new class implements Stringable {
            #[Override]
            public function __toString(): string
            {
                return 'user-7';
            }
        };

        self::assertSame('stdClass--user-7', EntityRefFactory::deriveName(stdClass::class, $id));
    }

    #[Test]
    public function rejectsNonStringableObjectId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not Stringable');
        EntityRefFactory::deriveName(stdClass::class, new stdClass());
    }

    #[Test]
    public function rejectsArrayId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be derived into an actor name');
        EntityRefFactory::deriveName(stdClass::class, ['k' => 1]);
    }

    #[Test]
    public function ofCachesByEntityId(): void
    {
        $callCount = 0;
        $makeAliveStub = function (): ActorRef {
            $stub = $this->createStub(ActorRef::class);
            $stub->method('isAlive')->willReturn(true);

            return $stub;
        };
        $stubRefs = [
            $makeAliveStub(),
            $makeAliveStub(),
        ];
        $spawner = new class ($stubRefs, $callCount) implements ActorSpawner {
            /** @param list<ActorRef> $refs */
            public function __construct(private readonly array $refs, private int &$callCount) {}

            #[Override]
            public function spawn(Props $props, string $name): ActorRef
            {
                return $this->refs[$this->callCount++];
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
        $refC = $factory->of('k2');

        self::assertSame($refA, $refB);
        self::assertNotSame($refA, $refC);
        self::assertSame(2, $callCount);
    }
}
