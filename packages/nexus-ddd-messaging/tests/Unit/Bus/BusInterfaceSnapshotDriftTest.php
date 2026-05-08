<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Bus;

use Monadial\Nexus\Ddd\Messaging\Bus\CommandBus;
use Monadial\Nexus\Ddd\Messaging\Bus\EventBus;
use Monadial\Nexus\Ddd\Messaging\Bus\QueryBus;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

#[CoversNothing]
final class BusInterfaceSnapshotDriftTest extends TestCase
{
    #[Test]
    public function commandBusHasExactlyOnePublicMethodNamedDispatchCommand(): void
    {
        $methods = (new ReflectionClass(CommandBus::class))->getMethods(ReflectionMethod::IS_PUBLIC);
        self::assertCount(1, $methods);
        self::assertSame('dispatchCommand', $methods[0]->getName());
        self::assertCount(1, $methods[0]->getParameters());
    }

    #[Test]
    public function queryBusHasExactlyOnePublicMethodNamedDispatchQuery(): void
    {
        $methods = (new ReflectionClass(QueryBus::class))->getMethods(ReflectionMethod::IS_PUBLIC);
        self::assertCount(1, $methods);
        self::assertSame('dispatchQuery', $methods[0]->getName());
    }

    #[Test]
    public function eventBusHasExactlyOnePublicMethodNamedPublishEvent(): void
    {
        $methods = (new ReflectionClass(EventBus::class))->getMethods(ReflectionMethod::IS_PUBLIC);
        self::assertCount(1, $methods);
        self::assertSame('publishEvent', $methods[0]->getName());
        $returnType = $methods[0]->getReturnType();
        self::assertInstanceOf(ReflectionNamedType::class, $returnType);
        self::assertSame('void', $returnType->getName());
    }
}
