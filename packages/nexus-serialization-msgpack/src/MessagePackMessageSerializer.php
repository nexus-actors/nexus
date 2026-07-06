<?php

declare(strict_types=1);

namespace Monadial\Nexus\Serialization\Msgpack;

use CuyZ\Valinor\Mapper\TreeMapper;
use CuyZ\Valinor\MapperBuilder;
use JsonException;
use Monadial\Nexus\Serialization\Exception\MessageDeserializationException;
use Monadial\Nexus\Serialization\Exception\MessageSerializationException;
use Monadial\Nexus\Serialization\MessageSerializer;
use Monadial\Nexus\Serialization\TypeRegistry;
use NoDiscard;
use Override;
use Throwable;

/**
 * @psalm-api
 *
 * Serializer using MessagePack encoding and Valinor for type-safe deserialization.
 *
 * Messages are normalized to an array via a JSON round-trip, then packed with
 * MessagePack. Deserialization unpacks the bytes and uses Valinor's mapper for
 * strict type reconstruction.
 *
 * @example
 *   $registry = new TypeRegistry();
 *   $registry->register(MyMessage::class, 'my.message');
 *   $serializer = new MessagePackMessageSerializer($registry);
 *   $bytes = $serializer->serialize(new MyMessage('hello'));
 *   $msg   = $serializer->deserialize($bytes, 'my.message');
 */
final readonly class MessagePackMessageSerializer implements MessageSerializer
{
    private TreeMapper $mapper;

    public function __construct(
        private TypeRegistry $registry,
        ?MapperBuilder $mapperBuilder = null,
        private MsgpackCodec $codec = new MsgpackCodec(),
    ) {
        $this->mapper = ($mapperBuilder ?? new MapperBuilder())
            ->allowPermissiveTypes()
            ->mapper();
    }

    /**
     * @throws MessageSerializationException
     */
    #[Override]
    #[NoDiscard]
    public function serialize(object $message): string
    {
        $className = $message::class;
        $typeName = $this->registry->nameForClass($className);

        if ($typeName === null) {
            throw new MessageSerializationException($className, "No type name registered for class '{$className}'");
        }

        try {
            /** @var array<string, mixed> $array */
            $array = json_decode(json_encode($message, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new MessageSerializationException($className, $e->getMessage(), $e);
        }

        try {
            return $this->codec->pack($array);
        } catch (Throwable $e) {
            throw new MessageSerializationException($className, $e->getMessage(), $e);
        }
    }

    /**
     * Deserializes MessagePack bytes to an object of the specified type.
     *
     * Type resolution attempts the registry first (type name lookup); if not found, treats $type as a literal class name.
     *
     * @throws MessageDeserializationException
     */
    #[Override]
    #[NoDiscard]
    public function deserialize(string $data, string $type): object
    {
        $className = $this->registry->classForName($type) ?? $type;

        try {
            $decoded = $this->codec->unpack($data);
        } catch (Throwable $e) {
            throw new MessageDeserializationException($type, $e->getMessage(), $e);
        }

        try {
            /** @var object $result */
            $result = $this->mapper->map($className, $decoded);
        } catch (Throwable $e) {
            throw new MessageDeserializationException($type, $e->getMessage(), $e);
        }

        return $result;
    }
}
