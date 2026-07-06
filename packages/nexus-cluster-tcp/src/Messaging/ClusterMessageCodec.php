<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Messaging;

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

    public function decode(string $type, string $body): object
    {
        return $this->serializer->deserialize($body, $type);
    }
}
