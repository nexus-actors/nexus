<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Routing;

use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Bus\Routing\RoutingStrategy;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

#[CoversNothing]
final class RoutingStrategyInterfaceTest extends TestCase
{
    #[Test]
    public function resolveMethodExists(): void
    {
        $reflection = new ReflectionClass(RoutingStrategy::class);

        self::assertTrue($reflection->hasMethod('resolve'));
    }

    #[Test]
    public function resolveAcceptsClassStringAndReturnsOption(): void
    {
        $method = new ReflectionClass(RoutingStrategy::class)->getMethod('resolve');
        $parameters = $method->getParameters();

        self::assertCount(1, $parameters);

        $messageType = $parameters[0]->getType();
        self::assertInstanceOf(ReflectionNamedType::class, $messageType);
        self::assertSame('string', $messageType->getName());

        $returnType = $method->getReturnType();
        self::assertInstanceOf(ReflectionNamedType::class, $returnType);
        self::assertSame(Option::class, $returnType->getName());
    }

    #[Test]
    public function resolveCarriesParameterAndReturnDocblock(): void
    {
        $doc = new ReflectionClass(RoutingStrategy::class)->getMethod('resolve')->getDocComment();

        self::assertIsString($doc);
        self::assertStringContainsString('@param class-string $messageClass', $doc);
        self::assertStringContainsString('@return Option<RoutingResolution>', $doc);
    }
}
