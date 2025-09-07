<?php

declare(strict_types=1);

namespace Monadial\Nexus\Serialization;

/**
 * Declares a stable type name for a message class.
 *
 * Used by TypeRegistry to map between class names and their wire-format type identifiers.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class MessageType
{
    public function __construct(
        public string $name,
    ) {}
}
