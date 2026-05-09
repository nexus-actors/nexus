<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Tests\Unit\Strategy;

use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Aggregate\Strategy\StatefulPersister;
use Monadial\Nexus\Ddd\Core\Aggregate\StatefulAggregateRoot;
use Monadial\Nexus\Ddd\Core\Identity\Identifier;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

#[CoversNothing]
final class StatefulPersisterInterfaceTest extends TestCase
{
    #[Test]
    public function loadAcceptsClassStringAndIdentifierAndReturnsOption(): void
    {
        $reflection = new ReflectionClass(StatefulPersister::class);
        $method = $reflection->getMethod('load');
        $params = $method->getParameters();

        self::assertCount(2, $params);
        self::assertSame('entityClass', $params[0]->getName());
        self::assertSame('id', $params[1]->getName());

        $idType = $params[1]->getType();
        self::assertInstanceOf(ReflectionNamedType::class, $idType);
        self::assertSame(Identifier::class, $idType->getName());

        $returnType = $method->getReturnType();
        self::assertInstanceOf(ReflectionNamedType::class, $returnType);
        self::assertSame(Option::class, $returnType->getName());
    }

    #[Test]
    public function persistAcceptsStatefulAggregateRootAndReturnsVoid(): void
    {
        $reflection = new ReflectionClass(StatefulPersister::class);
        $method = $reflection->getMethod('persist');
        $params = $method->getParameters();

        self::assertCount(1, $params);
        $entityType = $params[0]->getType();
        self::assertInstanceOf(ReflectionNamedType::class, $entityType);
        self::assertSame(StatefulAggregateRoot::class, $entityType->getName());

        $returnType = $method->getReturnType();
        self::assertInstanceOf(ReflectionNamedType::class, $returnType);
        self::assertSame('void', $returnType->getName());
    }
}
