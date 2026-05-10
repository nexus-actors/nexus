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
    public function commandBusExposesDispatchAndTryDispatch(): void
    {
        $names = self::publicMethodNames(CommandBus::class);

        self::assertSame(['dispatchCommand', 'tryDispatch'], $names);
    }

    #[Test]
    public function queryBusExposesDispatchAndTryAsk(): void
    {
        $names = self::publicMethodNames(QueryBus::class);

        self::assertSame(['dispatchQuery', 'tryAsk'], $names);
    }

    #[Test]
    public function eventBusExposesPublishAndTryPublish(): void
    {
        $reflection = new ReflectionClass(EventBus::class);
        $names = self::publicMethodNames(EventBus::class);

        self::assertSame(['publishEvent', 'tryPublish'], $names);

        $returnType = $reflection->getMethod('publishEvent')->getReturnType();
        self::assertInstanceOf(ReflectionNamedType::class, $returnType);
        self::assertSame('void', $returnType->getName());
    }

    /**
     * @param class-string $interface
     * @return list<string>
     */
    private static function publicMethodNames(string $interface): array
    {
        $methods = (new ReflectionClass($interface))->getMethods(ReflectionMethod::IS_PUBLIC);
        $names = array_map(static fn(ReflectionMethod $m) => $m->getName(), $methods);
        sort($names);

        return $names;
    }
}
