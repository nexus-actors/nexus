<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Serialization;

/**
 * @psalm-api
 * @psalm-immutable
 */
final readonly class SerializedMessage
{
    public function __construct(public string $body, public string $format, public string $messageClass,) {}
}
