<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Exception;

/** @psalm-api */
final class InvalidIdentifierException extends NexusDddException
{
    public static function malformed(string $identifierClass, string $value, string $reason): self
    {
        return new self(
            sprintf('Invalid %s "%s": %s', $identifierClass, $value, $reason),
        );
    }
}
