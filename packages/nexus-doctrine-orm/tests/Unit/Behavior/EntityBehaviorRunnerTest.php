<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Tests\Unit\Behavior;

use Doctrine\DBAL\Connection;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Doctrine\Orm\Behavior\EntityBehavior;
use Monadial\Nexus\Doctrine\Orm\Behavior\EntityBehaviorRunner;
use Monadial\Nexus\Doctrine\Orm\Behavior\EntityEffect;
use Monadial\Nexus\Doctrine\Orm\Behavior\ReplayPolicy\FailIfMissing;
use Monadial\Nexus\Doctrine\Orm\Pool\EntityManagerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(EntityBehaviorRunner::class)]
final class EntityBehaviorRunnerTest extends TestCase
{
    #[Test]
    public function toBehaviorReturnsActorBehavior(): void
    {
        $factory = $this->createStub(EntityManagerFactory::class);
        $conn = $this->createStub(Connection::class);

        /** @psalm-suppress MixedArgumentTypeCoercion, MixedArgument, UnusedClosureParam */
        $behavior = EntityBehavior::create(
            entityClass: stdClass::class,
            id: 'k',
            commandHandler: static fn(ActorContext $ctx, object $msg, object $entity): EntityEffect => EntityEffect::same(),
        )
            ->withEntityManagerFactory($factory)
            ->withReplayPolicy(new FailIfMissing())
            ->withConnectionSource(static fn(): Connection => $conn)
            ->toBehavior();

        self::assertInstanceOf(Behavior::class, $behavior);
    }
}
