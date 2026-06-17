<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Tests\Unit\Behavior;

use Monadial\Nexus\Doctrine\Orm\Behavior\EntityBehavior;
use Monadial\Nexus\Doctrine\Orm\Behavior\EntityBehaviorBuilder;
use Monadial\Nexus\Doctrine\Orm\Behavior\EntityEffect;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(EntityBehavior::class)]
final class EntityBehaviorTest extends TestCase
{
    #[Test]
    public function createReturnsBuilder(): void
    {
        /** @psalm-suppress UnusedClosureParam */
        $b = EntityBehavior::create(
            entityClass: stdClass::class,
            id: 'k',
            commandHandler: static fn($ctx, object $msg, object $entity): EntityEffect => EntityEffect::same(),
        );

        self::assertInstanceOf(EntityBehaviorBuilder::class, $b);
        self::assertSame(stdClass::class, $b->entityClass);
        self::assertSame('k', $b->id);
    }
}
