<?php

declare(strict_types=1);

namespace Monadial\Nexus\Serialization;

use Monadial\Nexus\Serialization\Exception\MessageDeserializationException;
use Monadial\Nexus\Serialization\Exception\MessageSerializationException;
use NoDiscard;

/**
 * @psalm-api
 *
 * Serializes and deserializes message objects to and from strings.
 */
interface MessageSerializer
{
    /**
     * Serializes a message object to a string representation.
     *
     * @throws MessageSerializationException
     */
    #[NoDiscard]
    public function serialize(object $message): string;

    /**
     * Deserializes a string representation back into a message object.
     *
     * @throws MessageDeserializationException
     */
    #[NoDiscard]
    public function deserialize(string $data, string $type): object;
}
