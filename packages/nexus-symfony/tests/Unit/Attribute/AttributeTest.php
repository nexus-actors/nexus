<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Tests\Unit\Attribute;

use Attribute;
use Monadial\Nexus\Symfony\Attribute\AsActor;
use Monadial\Nexus\Symfony\Attribute\AsActorHandler;
use Monadial\Nexus\Symfony\Attribute\AsGlobalActor;
use Monadial\Nexus\Symfony\Attribute\CoroutineScoped;
use Monadial\Nexus\Symfony\Attribute\WithActor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(AsActor::class)]
#[CoversClass(AsGlobalActor::class)]
#[CoversClass(AsActorHandler::class)]
#[CoversClass(WithActor::class)]
#[CoversClass(CoroutineScoped::class)]
final class AttributeTest extends TestCase
{
    #[Test]
    public function asActorHoldsName(): void
    {
        $attr = new AsActor(name: 'orders');

        self::assertSame('orders', $attr->name);
    }

    #[Test]
    public function asGlobalActorHoldsName(): void
    {
        $attr = new AsGlobalActor(name: 'saga');

        self::assertSame('saga', $attr->name);
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
        $ref  = new ReflectionClass(AsActor::class);
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
