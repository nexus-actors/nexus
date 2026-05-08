<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Resolution;

use Monadial\Nexus\Ddd\Messaging\Handler\QueryHandler;
use Monadial\Nexus\Ddd\Messaging\Message\Query;
use Monadial\Nexus\Ddd\Messaging\Resolution\QueryHandlerLocator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

#[CoversNothing]
final class QueryHandlerLocatorInterfaceTest extends TestCase
{
    #[Test]
    public function isInterfaceWithLocateMethod(): void
    {
        $reflection = new ReflectionClass(QueryHandlerLocator::class);
        self::assertTrue($reflection->isInterface());

        $method = $reflection->getMethod('locate');
        self::assertCount(1, $method->getParameters());

        $type = $method->getParameters()[0]->getType();
        self::assertInstanceOf(ReflectionNamedType::class, $type);
        self::assertSame(Query::class, $type->getName());

        $returnType = $method->getReturnType();
        self::assertInstanceOf(ReflectionNamedType::class, $returnType);
        self::assertSame(QueryHandler::class, $returnType->getName());
    }
}
