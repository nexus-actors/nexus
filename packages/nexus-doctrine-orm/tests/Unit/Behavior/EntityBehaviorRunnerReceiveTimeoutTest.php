<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Tests\Unit\Behavior;

use Doctrine\DBAL\Connection;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Doctrine\Orm\Behavior\EntityBehavior;
use Monadial\Nexus\Doctrine\Orm\Behavior\EntityBehaviorRunner;
use Monadial\Nexus\Doctrine\Orm\Behavior\EntityEffect;
use Monadial\Nexus\Doctrine\Orm\Pool\EntityManagerFactory;
use Monadial\Nexus\Runtime\Duration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(EntityBehaviorRunner::class)]
final class EntityBehaviorRunnerReceiveTimeoutTest extends TestCase
{
    #[Test]
    public function buildsBehaviorWithReceiveTimeoutConfigured(): void
    {
        $factory = $this->createStub(EntityManagerFactory::class);
        $conn = $this->createStub(Connection::class);

        $behavior = EntityBehavior::create(
            entityClass: stdClass::class,
            id: 'k',
            commandHandler: static fn(ActorContext $ctx, object $msg, object $entity): EntityEffect => EntityEffect::same(),
        )
            ->withEntityManagerFactory($factory)
            ->withConnectionSource(static fn(): Connection => $conn)
            ->withReceiveTimeout(Duration::millis(100))
            ->toBehavior();

        self::assertInstanceOf(Behavior::class, $behavior);
    }
}
