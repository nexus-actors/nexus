<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Middleware;

use Closure;
use Monadial\Nexus\Ddd\Bus\Middleware\Middleware;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

#[CoversNothing]
final class MiddlewareInterfaceTest extends TestCase
{
    #[Test]
    public function processMethodExists(): void
    {
        $reflection = new ReflectionClass(Middleware::class);

        self::assertTrue($reflection->hasMethod('process'));
    }

    #[Test]
    public function processAcceptsEnvelopeAndClosureAndReturnsMixed(): void
    {
        $method = new ReflectionClass(Middleware::class)->getMethod('process');
        $parameters = $method->getParameters();

        self::assertCount(2, $parameters);

        $envelopeType = $parameters[0]->getType();
        self::assertInstanceOf(ReflectionNamedType::class, $envelopeType);
        self::assertSame(Envelope::class, $envelopeType->getName());

        $nextType = $parameters[1]->getType();
        self::assertInstanceOf(ReflectionNamedType::class, $nextType);
        self::assertSame(Closure::class, $nextType->getName());

        $returnType = $method->getReturnType();
        self::assertInstanceOf(ReflectionNamedType::class, $returnType);
        self::assertSame('mixed', $returnType->getName());
    }

    #[Test]
    public function interfaceCarriesTemplateAnnotations(): void
    {
        $doc = new ReflectionClass(Middleware::class)->getDocComment();

        self::assertIsString($doc);
        self::assertStringContainsString('@template TIn of object', $doc);
        self::assertStringContainsString('@template TOut', $doc);
    }

    #[Test]
    public function processCarriesParameterAndReturnDocblock(): void
    {
        $doc = new ReflectionClass(Middleware::class)->getMethod('process')->getDocComment();

        self::assertIsString($doc);
        self::assertStringContainsString('@param Envelope<TIn>', $doc);
        self::assertStringContainsString('@param Closure(Envelope<TIn>): TOut', $doc);
        self::assertStringContainsString('@return TOut', $doc);
    }
}
