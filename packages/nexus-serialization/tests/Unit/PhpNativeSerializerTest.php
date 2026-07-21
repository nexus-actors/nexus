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
        $serializer = PhpNativeSerializer::forTrustedData();
        $message = new SimpleMessage('hello', 42);

        $data = $serializer->serialize($message);

        self::assertIsString($data);
        self::assertNotEmpty($data);
    }

    #[Test]
    public function deserializesBackToEqualObject(): void
    {
        $serializer = PhpNativeSerializer::forTrustedData();
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
        $serializer = PhpNativeSerializer::forTrustedData();
        $message = new NonSerializableMessage(fopen('php://memory', 'r'));

        $this->expectException(MessageSerializationException::class);

        (void) $serializer->serialize($message);
    }

    #[Test]
    public function deserializeInvalidDataThrows(): void
    {
        $serializer = PhpNativeSerializer::forTrustedData();

        $this->expectException(MessageDeserializationException::class);

        (void) $serializer->deserialize('not-valid-serialized-data', 'SomeType');
    }

    #[Test]
    public function deserializeWrongTypeThrows(): void
    {
        $serializer = PhpNativeSerializer::forTrustedData();
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
        $data = PhpNativeSerializer::forTrustedData()->serialize(new GadgetMessage());

        try {
            (void) $serializer->deserialize($data, SimpleMessage::class);
            self::fail('Expected MessageDeserializationException');
        } catch (MessageDeserializationException) {
            self::assertFalse(GadgetMessage::$awakened, 'Gadget __wakeup must not run for a disallowed class');
        }
    }

    #[Test]
    public function trustedDataPreservesNestedObjectGraph(): void
    {
        // forTrustedData (allow-any) must round-trip rich graphs with nested objects.
        $serializer = PhpNativeSerializer::forTrustedData();
        $data = $serializer->serialize(new NestedMessage(new SimpleMessage('inner', 7)));

        $result = $serializer->deserialize($data, NestedMessage::class);

        self::assertInstanceOf(NestedMessage::class, $result);
        self::assertInstanceOf(SimpleMessage::class, $result->inner);
        self::assertSame('inner', $result->inner->text);
    }

    #[Test]
    public function allowListPermitsRegisteredNestedGraph(): void
    {
        // The allow-list must include EVERY nested class, not just the top type.
        $serializer = new PhpNativeSerializer([NestedMessage::class, SimpleMessage::class]);
        $data = PhpNativeSerializer::forTrustedData()->serialize(new NestedMessage(new SimpleMessage('x', 1)));

        $result = $serializer->deserialize($data, NestedMessage::class);

        self::assertInstanceOf(NestedMessage::class, $result);
        self::assertSame('x', $result->inner->text);
    }

    #[Test]
    public function allowListRejectsGadgetNestedInsideAllowedTopType(): void
    {
        // The top type (Envelopeish) is allowed, but the nested gadget is NOT —
        // PHP would materialize the top object with an __PHP_Incomplete_Class
        // child; the deep scan must reject the whole graph and no gadget runs.
        GadgetMessage::$awakened = false;
        $serializer = new PhpNativeSerializer([WrapperMessage::class]);
        $data = PhpNativeSerializer::forTrustedData()->serialize(new WrapperMessage(new GadgetMessage()));

        try {
            (void) $serializer->deserialize($data, WrapperMessage::class);
            self::fail('Expected MessageDeserializationException for nested disallowed class');
        } catch (MessageDeserializationException) {
            self::assertFalse(GadgetMessage::$awakened, 'Nested gadget __wakeup must not run');
        }
    }

    #[Test]
    public function disallowedTopTypeConstructorNeverRuns(): void
    {
        ConstructorSpy::$constructed = 0;
        $serializer = new PhpNativeSerializer([SimpleMessage::class]);
        $data = PhpNativeSerializer::forTrustedData()->serialize(new ConstructorSpy());

        // Baseline: constructing directly bumps the counter.
        new ConstructorSpy();
        $before = ConstructorSpy::$constructed;

        try {
            (void) $serializer->deserialize($data, SimpleMessage::class);
            self::fail('Expected MessageDeserializationException');
        } catch (MessageDeserializationException) {
            // unserialize never calls the constructor, and the disallowed class
            // is refused entirely — the counter is unchanged by deserialization.
            self::assertSame($before, ConstructorSpy::$constructed);
        }
    }
}

final readonly class WrapperMessage
{
    public function __construct(public object $inner) {}
}

final class ConstructorSpy
{
    public static int $constructed = 0;

    public function __construct()
    {
        self::$constructed++;
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
