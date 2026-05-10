<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Validation;

use Monadial\Nexus\Ddd\Bus\Validation\ValidationContext;
use Monadial\Nexus\Ddd\Bus\Validation\Validator;
use Monadial\Nexus\Ddd\Bus\Validation\Violations;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

#[CoversNothing]
final class ValidatorInterfaceTest extends TestCase
{
    #[Test]
    public function validateAcceptsObjectAndContextAndReturnsViolations(): void
    {
        $reflection = new ReflectionClass(Validator::class);
        $method = $reflection->getMethod('validate');
        $params = $method->getParameters();

        self::assertCount(2, $params);

        self::assertSame('message', $params[0]->getName());
        $messageType = $params[0]->getType();
        self::assertInstanceOf(ReflectionNamedType::class, $messageType);
        self::assertSame('object', $messageType->getName());

        self::assertSame('context', $params[1]->getName());
        $contextType = $params[1]->getType();
        self::assertInstanceOf(ReflectionNamedType::class, $contextType);
        self::assertSame(ValidationContext::class, $contextType->getName());

        $returnType = $method->getReturnType();
        self::assertInstanceOf(ReflectionNamedType::class, $returnType);
        self::assertSame(Violations::class, $returnType->getName());
    }

    #[Test]
    public function interfaceExposesOnlyValidate(): void
    {
        $reflection = new ReflectionClass(Validator::class);
        $methods = array_map(static fn($m): string => $m->getName(), $reflection->getMethods());

        self::assertSame(['validate'], $methods);
    }
}
