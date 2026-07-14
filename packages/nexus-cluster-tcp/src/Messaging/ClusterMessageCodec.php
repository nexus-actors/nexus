<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Messaging;

use Monadial\Nexus\Serialization\Exception\MessageDeserializationException;
use Monadial\Nexus\Serialization\Exception\MessageSerializationException;
use Monadial\Nexus\Serialization\MessageSerializer;
use Monadial\Nexus\Serialization\TypeRegistry;

/**
 * @psalm-api
 *
 * Bridges a Nexus {@see MessageSerializer} and its {@see TypeRegistry} into the wire
 * shape the cluster needs: encoding yields both the registered type name (for the frame's
 * `messageType`) and the serialized body, decoding maps a type name + body back to an object.
 */
final readonly class ClusterMessageCodec
{
    public function __construct(private MessageSerializer $serializer, private TypeRegistry $registry) {}

    /**
     * @throws MessageSerializationException When the message class has no registered type.
     */
    public function encode(object $message): EncodedMessage
    {
        $class = $message::class;
        $type = $this->registry->nameForClass($class);

        if ($type === null) {
            throw new MessageSerializationException($class, "No cluster type name registered for class '{$class}'");
        }

        return new EncodedMessage($type, $this->serializer->serialize($message));
    }

    /**
     * Decode a wire frame body to an object.
     *
     * The `$type` string arrives off the network and is therefore untrusted. It MUST resolve
     * to a whitelisted {@see \Monadial\Nexus\Serialization\MessageType} in the registry: a peer
     * must never be able to drive instantiation of an arbitrary class by naming it on the wire.
     * Unregistered types are rejected here (the underlying serializer would otherwise fall back
     * to treating `$type` as a literal class name).
     *
     * @throws MessageDeserializationException When `$type` is not a registered cluster type.
     */
    public function decode(string $type, string $body): object
    {
        if ($this->registry->classForName($type) === null) {
            throw new MessageDeserializationException($type, "No cluster type name registered for type '{$type}'");
        }

        return $this->serializer->deserialize($body, $type);
    }
}
