<?php

declare(strict_types=1);

namespace Monadial\Nexus\Serialization;

use Monadial\Nexus\Serialization\Exception\MessageDeserializationException;
use Monadial\Nexus\Serialization\Exception\MessageSerializationException;

/**
 * Serializer using PHP's native serialize()/unserialize().
 */
final readonly class PhpNativeSerializer implements MessageSerializer
{
    /**
     * @throws MessageSerializationException @phpstan-ignore throws.unusedType
     */
    #[\NoDiscard]
    public function serialize(object $message): string
    {
        try {
            return \serialize($message);
        } catch (\Throwable $e) { // @phpstan-ignore catch.neverThrown
            throw new MessageSerializationException($message::class, $e->getMessage(), $e);
        }
    }

    /**
     * @throws MessageDeserializationException
     */
    #[\NoDiscard]
    public function deserialize(string $data, string $type): object
    {
        try {
            $result = @\unserialize($data);
        } catch (\Throwable $e) { // @phpstan-ignore catch.neverThrown
            throw new MessageDeserializationException($type, $e->getMessage(), $e);
        }

        if ($result === false) {
            throw new MessageDeserializationException($type, 'Failed to unserialize data');
        }

        if (!is_object($result)) {
            throw new MessageDeserializationException($type, 'Unserialized data is not an object');
        }

        if (!$result instanceof $type) {
            throw new MessageDeserializationException(
                $type,
                'Expected instance of ' . $type . ', got ' . $result::class,
            );
        }

        return $result;
    }
}
