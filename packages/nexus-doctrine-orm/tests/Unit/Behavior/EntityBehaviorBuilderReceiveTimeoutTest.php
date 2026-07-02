<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Tests\Unit\Behavior;

use Monadial\Nexus\Doctrine\Orm\Behavior\EntityBehaviorBuilder;
use Monadial\Nexus\Doctrine\Orm\Behavior\EntityEffect;
use Monadial\Nexus\Doctrine\Orm\Pool\EntityManagerFactory;
use Monadial\Nexus\Runtime\Duration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(EntityBehaviorBuilder::class)]
final class EntityBehaviorBuilderReceiveTimeoutTest extends TestCase
{
    #[Test]
    public function defaultReceiveTimeoutIsNull(): void
    {
        $builder = $this->builder();

        self::assertNull($builder->receiveTimeout);
    }

    #[Test]
    public function withReceiveTimeoutReturnsNewInstance(): void
    {
        $base = $this->builder();
        $configured = $base->withReceiveTimeout(Duration::seconds(60));

        self::assertNotSame($base, $configured);
        self::assertNull($base->receiveTimeout);
        self::assertNotNull($configured->receiveTimeout);
        self::assertTrue($configured->receiveTimeout->equals(Duration::seconds(60)));
    }

    #[Test]
    public function receiveTimeoutSurvivesOtherWithCalls(): void
    {
        $factory = $this->createStub(EntityManagerFactory::class);

        $builder = $this->builder()
            ->withReceiveTimeout(Duration::seconds(60))
            ->withEntityManagerFactory($factory);

        self::assertNotNull($builder->receiveTimeout);
        self::assertTrue($builder->receiveTimeout->equals(Duration::seconds(60)));
        self::assertSame($factory, $builder->emFactory);
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
