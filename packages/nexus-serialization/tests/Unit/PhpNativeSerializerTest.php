<?php

declare(strict_types=1);

namespace Monadial\Nexus\Serialization\Tests\Unit;

use Monadial\Nexus\Serialization\Exception\MessageDeserializationException;
use Monadial\Nexus\Serialization\Exception\MessageSerializationException;
use Monadial\Nexus\Serialization\PhpNativeSerializer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PhpNativeSerializer::class)]
final class PhpNativeSerializerTest extends TestCase
{
    #[Test]
    public function serializesReadonlyMessage(): void
    {
        $serializer = new PhpNativeSerializer();
        $message = new SimpleMessage('hello', 42);

        $data = $serializer->serialize($message);

        self::assertIsString($data);
        self::assertNotEmpty($data);
    }

    #[Test]
    public function deserializesBackToEqualObject(): void
    {
        $serializer = new PhpNativeSerializer();
        $message = new SimpleMessage('hello', 42);

        $data = $serializer->serialize($message);
        $result = $serializer->deserialize($data, SimpleMessage::class);

        self::assertInstanceOf(SimpleMessage::class, $result);
        self::assertSame('hello', $result->text);
        self::assertSame(42, $result->number);
    }

    #[Test]
    public function serializeNonSerializableThrows(): void
    {
        $serializer = new PhpNativeSerializer();
        $message = new NonSerializableMessage(fopen('php://memory', 'r'));

        $this->expectException(MessageSerializationException::class);

        (void) $serializer->serialize($message);
    }

    #[Test]
    public function deserializeInvalidDataThrows(): void
    {
        $serializer = new PhpNativeSerializer();

        $this->expectException(MessageDeserializationException::class);

        (void) $serializer->deserialize('not-valid-serialized-data', 'SomeType');
    }

    #[Test]
    public function deserializeWrongTypeThrows(): void
    {
        $serializer = new PhpNativeSerializer();
        $message = new SimpleMessage('hello', 42);
        $data = $serializer->serialize($message);

        $this->expectException(MessageDeserializationException::class);

        (void) $serializer->deserialize($data, \stdClass::class);
    }
}

final readonly class SimpleMessage
{
    public function __construct(
        public string $text,
        public int $number,
    ) {}
}

final class NonSerializableMessage
{
    /**
     * @param resource|false $handle
     */
    public function __construct(
        public mixed $handle,
    ) {}

    /**
     * @return never
     */
    public function __serialize(): array
    {
        throw new \RuntimeException('Cannot serialize');
    }
}
