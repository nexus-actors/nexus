<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Tests\Unit\Repository;

use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Aggregate\Repository\AggregateRepository;
use Monadial\Nexus\Ddd\Core\Aggregate\AggregateRoot;
use Monadial\Nexus\Ddd\Core\Identity\Identifier;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

#[CoversNothing]
final class AggregateRepositoryInterfaceTest extends TestCase
{
    #[Test]
    public function findAcceptsIdentifierAndReturnsOption(): void
    {
        $reflection = new ReflectionClass(AggregateRepository::class);
        $method = $reflection->getMethod('find');
        $params = $method->getParameters();

        self::assertCount(1, $params);
        self::assertSame('id', $params[0]->getName());

        $idType = $params[0]->getType();
        self::assertInstanceOf(ReflectionNamedType::class, $idType);
        self::assertSame(Identifier::class, $idType->getName());

        $returnType = $method->getReturnType();
        self::assertInstanceOf(ReflectionNamedType::class, $returnType);
        self::assertSame(Option::class, $returnType->getName());
    }

    #[Test]
    public function saveAcceptsAggregateRootAndReturnsVoid(): void
    {
        $reflection = new ReflectionClass(AggregateRepository::class);
        $method = $reflection->getMethod('save');
        $params = $method->getParameters();

        self::assertCount(1, $params);
        $aggregateType = $params[0]->getType();
        self::assertInstanceOf(ReflectionNamedType::class, $aggregateType);
        self::assertSame(AggregateRoot::class, $aggregateType->getName());

        $returnType = $method->getReturnType();
        self::assertInstanceOf(ReflectionNamedType::class, $returnType);
        self::assertSame('void', $returnType->getName());
    }

    #[Test]
    public function interfaceDoesNotExposeAddMethod(): void
    {
        $reflection = new ReflectionClass(AggregateRepository::class);
        self::assertFalse($reflection->hasMethod('add'));
    }
}
