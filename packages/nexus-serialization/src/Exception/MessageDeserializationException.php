<?php
declare(strict_types=1);

namespace Monadial\Nexus\Serialization\Exception;

use Throwable;

/**
 * @psalm-api
 *
 * Thrown when data cannot be deserialized into a message.
 */
final class MessageDeserializationException extends SerializationException
{
    public function __construct(
        public readonly string $typeName,
        public readonly string $reason,
        ?Throwable $previous = null,
    ) {
        parent::__construct("Failed to deserialize {$typeName}: {$reason}", 0, $previous);
    }
}
