<?php

declare(strict_types=1);

namespace Monadial\Nexus\Serialization\Msgpack\Tests\Unit;

use Monadial\Nexus\Serialization\Exception\MessageDeserializationException;
use Monadial\Nexus\Serialization\Exception\MessageSerializationException;
use Monadial\Nexus\Serialization\Msgpack\MessagePackMessageSerializer;
use Monadial\Nexus\Serialization\Msgpack\MsgpackCodec;
use Monadial\Nexus\Serialization\Msgpack\Tests\Fixture\MsgpackTestMessage;
use Monadial\Nexus\Serialization\TypeRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(MessagePackMessageSerializer::class)]
final class MessagePackMessageSerializerTest extends TestCase
{
    private TypeRegistry $registry;
    private MessagePackMessageSerializer $serializer;

    #[Test]
    public function roundtripRegisteredType(): void
    {
        $original = new MsgpackTestMessage('hello', 42);

        $bytes = $this->serializer->serialize($original);
        $restored = $this->serializer->deserialize($bytes, 'msgpack.test');

        self::assertInstanceOf(MsgpackTestMessage::class, $restored);
        self::assertSame($original->text, $restored->text);
        self::assertSame($original->number, $restored->number);
    }

    #[Test]
    public function classNameFallbackRoundtrip(): void
    {
        $emptyRegistry = new TypeRegistry();
        $serializer = new MessagePackMessageSerializer($emptyRegistry);

        // Serialize by registering the class directly for the serialize step,
        // then deserialize using the FQCN directly (class-name fallback).
        $directRegistry = new TypeRegistry();
        $directRegistry->register(MsgpackTestMessage::class, 'direct.test');
        $directSerializer = new MessagePackMessageSerializer($directRegistry);

        $bytes = $directSerializer->serialize(new MsgpackTestMessage('direct-class', 77));
        $restored = $serializer->deserialize($bytes, MsgpackTestMessage::class);

        self::assertInstanceOf(MsgpackTestMessage::class, $restored);
        self::assertSame('direct-class', $restored->text);
        self::assertSame(77, $restored->number);
    }

    #[Test]
    public function serializeUnregisteredClassThrows(): void
    {
        $this->expectException(MessageSerializationException::class);

        (void) $this->serializer->serialize(new stdClass());
    }

    #[Test]
    public function garbageBytesThrowDeserializationException(): void
    {
        $this->expectException(MessageDeserializationException::class);

        // 0xcd = uint16 format requiring 2 more bytes; truncated → InsufficientDataException wrapped.
        (void) $this->serializer->deserialize("\xcd", 'msgpack.test');
    }

    #[Test]
    public function structurallyWrongPayloadThrowsDeserializationException(): void
    {
        $this->expectException(MessageDeserializationException::class);

        // Missing required 'number' field — Valinor mapping will fail.
        $codec = new MsgpackCodec(false);
        $bytes = $codec->pack(['text' => 'hello']);

        (void) $this->serializer->deserialize($bytes, 'msgpack.test');
    }

    #[Test]
    public function outputIsBinaryNotJson(): void
    {
        $serializer = new MessagePackMessageSerializer($this->registry, codec: new MsgpackCodec(false));
        $message = new MsgpackTestMessage('hi', 42);

        $bytes = $serializer->serialize($message);
        $json = json_encode($message, JSON_THROW_ON_ERROR);

        self::assertNotSame($bytes, $json);
        self::assertLessThan(strlen($json), strlen($bytes));
        self::assertMatchesRegularExpression('/[\x00-\x1f\x80-\xff]/', $bytes);
    }

    #[Test]
    public function rybakitSelfParityRoundtrip(): void
    {
        $serializer = new MessagePackMessageSerializer($this->registry, codec: new MsgpackCodec(false));
        $original = new MsgpackTestMessage('rybakit-round-trip', 777);

        $bytes = $serializer->serialize($original);
        $restored = $serializer->deserialize($bytes, 'msgpack.test');

        self::assertInstanceOf(MsgpackTestMessage::class, $restored);
        self::assertSame($original->text, $restored->text);
        self::assertSame($original->number, $restored->number);
    }

    #[Test]
    #[RequiresPhpExtension('msgpack')]
    public function extAndPureCodecAreWireCompatible(): void
    {
        $array = ['number' => 42, 'text' => 'hello'];

        $extCodec = new MsgpackCodec(true);
        $pureCodec = new MsgpackCodec(false);

        // Pack with ext, unpack with pure
        $extPacked = $extCodec->pack($array);
        self::assertSame($array, $pureCodec->unpack($extPacked));

        // Pack with pure, unpack with ext
        $purePacked = $pureCodec->pack($array);
        self::assertSame($array, $extCodec->unpack($purePacked));
    }

    protected function setUp(): void
    {
        $this->registry = new TypeRegistry();
        $this->registry->register(MsgpackTestMessage::class, 'msgpack.test');
        $this->serializer = new MessagePackMessageSerializer($this->registry);
    }
}
