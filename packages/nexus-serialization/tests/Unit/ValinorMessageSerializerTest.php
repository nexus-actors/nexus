<?php
declare(strict_types=1);

namespace Monadial\Nexus\Serialization\Tests\Unit;

use Monadial\Nexus\Serialization\Exception\MessageDeserializationException;
use Monadial\Nexus\Serialization\Exception\MessageSerializationException;
use Monadial\Nexus\Serialization\TypeRegistry;
use Monadial\Nexus\Serialization\ValinorMessageSerializer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(ValinorMessageSerializer::class)]
final class ValinorMessageSerializerTest extends TestCase
{
    private TypeRegistry $registry;
    private ValinorMessageSerializer $serializer;

    #[Test]
    public function serializesSimpleReadonlyMessageToJson(): void
    {
        $message = new ValinorTestMessage('hello', 42);

        $json = $this->serializer->serialize($message);

        $decoded = json_decode($json, true);
        self::assertIsArray($decoded);
        self::assertSame('hello', $decoded['text']);
        self::assertSame(42, $decoded['number']);
    }

    #[Test]
    public function deserializesJsonToCorrectObject(): void
    {
        $json = json_encode(['text' => 'world', 'number' => 99], JSON_THROW_ON_ERROR);

        $result = $this->serializer->deserialize($json, 'valinor.test');

        self::assertInstanceOf(ValinorTestMessage::class, $result);
        self::assertSame('world', $result->text);
        self::assertSame(99, $result->number);
    }

    #[Test]
    public function roundtripPreservesMessage(): void
    {
        $original = new ValinorTestMessage('roundtrip', 123);

        $json = $this->serializer->serialize($original);
        $restored = $this->serializer->deserialize($json, 'valinor.test');

        self::assertInstanceOf(ValinorTestMessage::class, $restored);
        self::assertSame($original->text, $restored->text);
        self::assertSame($original->number, $restored->number);
    }

    #[Test]
    public function unknownTypeThrowsDeserializationException(): void
    {
        $this->expectException(MessageDeserializationException::class);
        $this->expectExceptionMessage('unknown.type');

        (void) $this->serializer->deserialize('{"text":"hello"}', 'unknown.type');
    }

    #[Test]
    public function valinorErrorWrappedInDeserializationException(): void
    {
        $this->expectException(MessageDeserializationException::class);

        // Invalid JSON structure — missing required 'number' field
        (void) $this->serializer->deserialize('{"text":"hello"}', 'valinor.test');
    }

    #[Test]
    public function serializeUnregisteredClassThrows(): void
    {
        $this->expectException(MessageSerializationException::class);

        (void) $this->serializer->serialize(new stdClass());
    }

    #[Test]
    public function invalidJsonThrowsDeserializationException(): void
    {
        $this->expectException(MessageDeserializationException::class);

        (void) $this->serializer->deserialize('{invalid-json', 'valinor.test');
    }

    protected function setUp(): void
    {
        $this->registry = new TypeRegistry();
        $this->registry->register(ValinorTestMessage::class, 'valinor.test');
        $this->serializer = new ValinorMessageSerializer($this->registry);
    }
}

final readonly class ValinorTestMessage
{
    public function __construct(public string $text, public int $number,) {}
}
