<?php

declare(strict_types=1);

namespace Monadial\Nexus\Serialization\Exception;

/**
 * Thrown when a message cannot be serialized.
 */
final class MessageSerializationException extends SerializationException
{
    public function __construct(
        public readonly string $messageClass,
        public readonly string $reason,
        ?\Throwable $previous = null,
    ) {
        parent::__construct("Failed to serialize {$messageClass}: {$reason}", 0, $previous);
    }
}
