<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Bus;

use Monadial\Nexus\Ddd\Messaging\Bus\CommandBus;
use Monadial\Nexus\Ddd\Messaging\Bus\EnvelopedCommandBus;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

#[CoversNothing]
final class EnvelopedCommandBusInterfaceTest extends TestCase
{
    #[Test]
    public function extendsCommandBusAndDeclaresDispatchEnvelopedTakingEnvelope(): void
    {
        $reflection = new ReflectionClass(EnvelopedCommandBus::class);
        self::assertTrue($reflection->isInterface());
        self::assertContains(CommandBus::class, $reflection->getInterfaceNames());

        $method = $reflection->getMethod('dispatchEnveloped');
        self::assertCount(1, $method->getParameters());

        $type = $method->getParameters()[0]->getType();
        self::assertInstanceOf(ReflectionNamedType::class, $type);
        self::assertSame(Envelope::class, $type->getName());

        $returnType = $method->getReturnType();
        self::assertInstanceOf(ReflectionNamedType::class, $returnType);
        self::assertSame('void', $returnType->getName());
    }

    #[Test]
    public function isInternalPerDocblock(): void
    {
        $reflection = new ReflectionClass(EnvelopedCommandBus::class);
        $doc = $reflection->getDocComment();
        self::assertIsString($doc);
        self::assertStringContainsString('@internal', $doc);
    }
}
