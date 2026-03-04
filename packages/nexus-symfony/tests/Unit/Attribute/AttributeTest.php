<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Tests\Unit\Attribute;

use Attribute;
use Monadial\Nexus\Symfony\Attribute\Actor;
use Monadial\Nexus\Symfony\Attribute\ActorType;
use Monadial\Nexus\Symfony\Attribute\AsActorHandler;
use Monadial\Nexus\Symfony\Attribute\CoroutineScoped;
use Monadial\Nexus\Symfony\Attribute\WithActor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(Actor::class)]
#[CoversClass(ActorType::class)]
#[CoversClass(AsActorHandler::class)]
#[CoversClass(WithActor::class)]
#[CoversClass(CoroutineScoped::class)]
final class AttributeTest extends TestCase
{
    #[Test]
    public function testActorAttribute(): void
    {
        $attr = new Actor(ActorType::Isolated, 'orders');

        self::assertSame(ActorType::Isolated, $attr->type);
        self::assertSame('orders', $attr->name);
    }

    #[Test]
    public function testActorSharedAttribute(): void
    {
        $attr = new Actor(ActorType::Shared, 'catalog');

        self::assertSame(ActorType::Shared, $attr->type);
        self::assertSame('catalog', $attr->name);
    }

    #[Test]
    public function testActorTypeEnum(): void
    {
        self::assertSame('Isolated', ActorType::Isolated->name);
        self::assertSame('Shared', ActorType::Shared->name);
    }

    #[Test]
    public function withActorHoldsName(): void
    {
        $attr = new WithActor('orders');

        self::assertSame('orders', $attr->name);
    }

    #[Test]
    public function asActorTargetsClass(): void
    {
        $ref  = new ReflectionClass(Actor::class);
        $attr = $ref->getAttributes(Attribute::class)[0]->newInstance();

        self::assertSame(Attribute::TARGET_CLASS, $attr->flags);
    }

    #[Test]
    public function asActorHandlerTargetsMethod(): void
    {
        $ref  = new ReflectionClass(AsActorHandler::class);
        $attr = $ref->getAttributes(Attribute::class)[0]->newInstance();

        self::assertSame(Attribute::TARGET_METHOD, $attr->flags);
    }
}
