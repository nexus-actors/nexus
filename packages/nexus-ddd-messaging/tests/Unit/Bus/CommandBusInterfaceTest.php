<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Bus;

use Fp\Functional\Either\Either;
use Monadial\Nexus\Ddd\Messaging\Bus\CommandBus;
use Monadial\Nexus\Ddd\Messaging\Message\Command;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

#[CoversNothing]
final class CommandBusInterfaceTest extends TestCase
{
    #[Test]
    public function commandBusIsInterfaceWithDispatchCommand(): void
    {
        $reflection = new ReflectionClass(CommandBus::class);
        self::assertTrue($reflection->isInterface());

        $method = $reflection->getMethod('dispatchCommand');
        self::assertCount(1, $method->getParameters());

        $param = $method->getParameters()[0];
        $type = $param->getType();
        self::assertInstanceOf(ReflectionNamedType::class, $type);
        self::assertSame(Command::class, $type->getName());

        $returnType = $method->getReturnType();
        self::assertInstanceOf(ReflectionNamedType::class, $returnType);
        self::assertSame('void', $returnType->getName());
    }

    #[Test]
    public function tryDispatchTakesCommandAndReturnsEither(): void
    {
        $reflection = new ReflectionClass(CommandBus::class);
        $method = $reflection->getMethod('tryDispatch');

        self::assertCount(1, $method->getParameters());

        $param = $method->getParameters()[0];
        $type = $param->getType();
        self::assertInstanceOf(ReflectionNamedType::class, $type);
        self::assertSame(Command::class, $type->getName());

        $returnType = $method->getReturnType();
        self::assertInstanceOf(ReflectionNamedType::class, $returnType);
        self::assertSame(Either::class, $returnType->getName());
    }

    #[Test]
    public function tryDispatchDocblockCarriesEitherThrowableAccepted(): void
    {
        $reflection = new ReflectionClass(CommandBus::class);
        $doc = $reflection->getMethod('tryDispatch')->getDocComment();

        self::assertIsString($doc);
        self::assertStringContainsString('@return Either<Throwable, Accepted>', $doc);
    }
}
