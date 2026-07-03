<?php

declare(strict_types=1);

namespace Monadial\Nexus\Serialization\Tests\Unit;

use Monadial\Nexus\Serialization\Exception\MessageDeserializationException;
use Monadial\Nexus\Serialization\Exception\MessageSerializationException;
use Monadial\Nexus\Serialization\PhpNativeSerializer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

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

        (void) $serializer->deserialize($data, stdClass::class);
    }

    #[Test]
    public function allowListRejectsClassOutsideItWithoutRunningItsGadget(): void
    {
        // Restricted serializer: only SimpleMessage may be instantiated.
        $serializer = new PhpNativeSerializer(allowedClasses: [SimpleMessage::class]);
        GadgetMessage::$awakened = false;
        $data = (new PhpNativeSerializer())->serialize(new GadgetMessage());

        try {
            (void) $serializer->deserialize($data, SimpleMessage::class);
            self::fail('Expected MessageDeserializationException');
        } catch (MessageDeserializationException) {
            self::assertFalse(GadgetMessage::$awakened, 'Gadget __wakeup must not run for a disallowed class');
        }
    }

    #[Test]
    public function defaultPreservesNestedObjectGraph(): void
    {
        // Default (no allow-list) must round-trip rich graphs with nested objects.
        $serializer = new PhpNativeSerializer();
        $data = $serializer->serialize(new NestedMessage(new SimpleMessage('inner', 7)));

        $result = $serializer->deserialize($data, NestedMessage::class);

        self::assertInstanceOf(NestedMessage::class, $result);
        self::assertInstanceOf(SimpleMessage::class, $result->inner);
        self::assertSame('inner', $result->inner->text);
    }
}

final readonly class NestedMessage
{
    public function __construct(public SimpleMessage $inner) {}
}

final class GadgetMessage
{
    public static bool $awakened = false;

    public function __wakeup(): void
    {
        self::$awakened = true;
    }
}

final readonly class SimpleMessage
{
    public function __construct(public string $text, public int $number) {}
}

final class NonSerializableMessage
{
    /**
     * @param resource|false $handle
     */
    public function __construct(public mixed $handle) {}

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new RuntimeException('Cannot serialize');
    }
}
