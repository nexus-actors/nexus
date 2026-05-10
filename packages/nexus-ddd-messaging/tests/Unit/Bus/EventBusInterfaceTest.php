<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Bus;

use Fp\Functional\Either\Either;
use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\Messaging\Bus\EventBus;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

#[CoversNothing]
final class EventBusInterfaceTest extends TestCase
{
    #[Test]
    public function eventBusIsInterfaceWithPublishEvent(): void
    {
        $reflection = new ReflectionClass(EventBus::class);
        self::assertTrue($reflection->isInterface());

        $method = $reflection->getMethod('publishEvent');
        self::assertCount(1, $method->getParameters());

        $param = $method->getParameters()[0];
        $type = $param->getType();
        self::assertInstanceOf(ReflectionNamedType::class, $type);
        self::assertSame(DomainEvent::class, $type->getName());

        $returnType = $method->getReturnType();
        self::assertInstanceOf(ReflectionNamedType::class, $returnType);
        self::assertSame('void', $returnType->getName());
    }

    #[Test]
    public function tryPublishTakesDomainEventAndReturnsEither(): void
    {
        $reflection = new ReflectionClass(EventBus::class);
        $method = $reflection->getMethod('tryPublish');

        self::assertCount(1, $method->getParameters());

        $param = $method->getParameters()[0];
        $type = $param->getType();
        self::assertInstanceOf(ReflectionNamedType::class, $type);
        self::assertSame(DomainEvent::class, $type->getName());

        $returnType = $method->getReturnType();
        self::assertInstanceOf(ReflectionNamedType::class, $returnType);
        self::assertSame(Either::class, $returnType->getName());
    }

    #[Test]
    public function tryPublishDocblockCarriesEitherThrowableAccepted(): void
    {
        $reflection = new ReflectionClass(EventBus::class);
        $doc = $reflection->getMethod('tryPublish')->getDocComment();

        self::assertIsString($doc);
        self::assertStringContainsString('@return Either<Throwable, Accepted>', $doc);
    }
}
