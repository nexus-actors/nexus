<?php

declare(strict_types=1);

namespace Monadial\Nexus\Serialization\Tests\Unit;

use LogicException;
use Monadial\Nexus\Serialization\MessageType;
use Monadial\Nexus\Serialization\TypeRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(TypeRegistry::class)]
final class TypeRegistryTest extends TestCase
{
    #[Test]
    public function registersClassNameAndTypeName(): void
    {
        $registry = new TypeRegistry();
        $registry->register(TestMessage::class, 'test.message');

        self::assertSame(TestMessage::class, $registry->classForName('test.message')->get());
        self::assertSame('test.message', $registry->nameForClass(TestMessage::class)->get());
    }

    #[Test]
    public function lookupByNameReturnsClass(): void
    {
        $registry = new TypeRegistry();
        $registry->register(TestMessage::class, 'test.message');

        $result = $registry->classForName('test.message');

        self::assertTrue($result->isSome());
        self::assertSame(TestMessage::class, $result->get());
    }

    #[Test]
    public function lookupByClassReturnsName(): void
    {
        $registry = new TypeRegistry();
        $registry->register(TestMessage::class, 'test.message');

        $result = $registry->nameForClass(TestMessage::class);

        self::assertTrue($result->isSome());
        self::assertSame('test.message', $result->get());
    }

    #[Test]
    public function duplicateNameThrows(): void
    {
        $registry = new TypeRegistry();
        $registry->register(TestMessage::class, 'test.message');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('test.message');

        $registry->register(AnotherTestMessage::class, 'test.message');
    }

    #[Test]
    public function registersViaMessageTypeAttribute(): void
    {
        $registry = new TypeRegistry();
        $registry->registerFromAttribute(AnnotatedMessage::class);

        self::assertSame(AnnotatedMessage::class, $registry->classForName('annotated.message')->get());
        self::assertSame('annotated.message', $registry->nameForClass(AnnotatedMessage::class)->get());
    }

    #[Test]
    public function registerFromAttributeThrowsWhenNoAttribute(): void
    {
        $registry = new TypeRegistry();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(TestMessage::class);

        $registry->registerFromAttribute(TestMessage::class);
    }

    #[Test]
    public function unknownTypeReturnsNone(): void
    {
        $registry = new TypeRegistry();

        self::assertTrue($registry->classForName('nonexistent')->isNone());
        self::assertTrue($registry->nameForClass('NonExistent\\Class')->isNone());
    }
}

final readonly class TestMessage
{
    public function __construct(public string $content) {}
}

final readonly class AnotherTestMessage
{
    public function __construct(public string $content) {}
}

#[MessageType('annotated.message')]
final readonly class AnnotatedMessage
{
    public function __construct(public string $content) {}
}
