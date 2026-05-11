<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Cli;

use Monadial\Nexus\Ddd\Bus\Cli\Command;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

#[CoversNothing]
final class CommandInterfaceTest extends TestCase
{
    #[Test]
    public function declaresRunWithStringReturn(): void
    {
        $reflection = new ReflectionClass(Command::class);

        self::assertTrue($reflection->hasMethod('run'));

        $method = $reflection->getMethod('run');
        $returnType = $method->getReturnType();

        self::assertInstanceOf(ReflectionNamedType::class, $returnType);
        self::assertSame('string', $returnType->getName());
    }

    #[Test]
    public function runAcceptsArrayArg(): void
    {
        $reflection = new ReflectionClass(Command::class);
        $params = $reflection->getMethod('run')->getParameters();

        self::assertCount(1, $params);
        self::assertSame('args', $params[0]->getName());
    }
}
