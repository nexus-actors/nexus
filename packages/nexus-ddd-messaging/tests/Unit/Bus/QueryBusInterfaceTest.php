<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Bus;

use Monadial\Nexus\Ddd\Messaging\Bus\QueryBus;
use Monadial\Nexus\Ddd\Messaging\Message\Query;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

#[CoversNothing]
final class QueryBusInterfaceTest extends TestCase
{
    #[Test]
    public function queryBusDispatchSignatureIsSingleArgumentReturningMixed(): void
    {
        $reflection = new ReflectionClass(QueryBus::class);
        self::assertTrue($reflection->isInterface());

        $method = $reflection->getMethod('dispatchQuery');
        self::assertCount(1, $method->getParameters());

        $param = $method->getParameters()[0];
        $type = $param->getType();
        self::assertInstanceOf(ReflectionNamedType::class, $type);
        self::assertSame(Query::class, $type->getName());

        $returnType = $method->getReturnType();
        self::assertInstanceOf(ReflectionNamedType::class, $returnType);
        self::assertSame('mixed', $returnType->getName());
    }

    #[Test]
    public function dispatchQueryDocblockCarriesTemplate(): void
    {
        $reflection = new ReflectionClass(QueryBus::class);
        $doc = $reflection->getMethod('dispatchQuery')->getDocComment();
        self::assertIsString($doc);
        self::assertStringContainsString('@template TResult', $doc);
        self::assertStringContainsString('@return TResult', $doc);
    }
}
