<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Resolution;

use Monadial\Nexus\Ddd\Messaging\Handler\CommandHandler;
use Monadial\Nexus\Ddd\Messaging\Message\Command;
use Monadial\Nexus\Ddd\Messaging\Resolution\CommandHandlerLocator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

#[CoversNothing]
final class CommandHandlerLocatorInterfaceTest extends TestCase
{
    #[Test]
    public function isInterfaceWithLocateMethod(): void
    {
        $reflection = new ReflectionClass(CommandHandlerLocator::class);
        self::assertTrue($reflection->isInterface());

        $method = $reflection->getMethod('locate');
        self::assertCount(1, $method->getParameters());

        $type = $method->getParameters()[0]->getType();
        self::assertInstanceOf(ReflectionNamedType::class, $type);
        self::assertSame(Command::class, $type->getName());

        $returnType = $method->getReturnType();
        self::assertInstanceOf(ReflectionNamedType::class, $returnType);
        self::assertSame(CommandHandler::class, $returnType->getName());
    }
}
