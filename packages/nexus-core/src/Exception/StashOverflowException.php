<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Exception;

/** @psalm-api */
final class StashOverflowException extends NexusException
{
    public function __construct(public readonly int $capacity, public readonly int $size)
    {
        parent::__construct("Stash buffer overflow: capacity {$capacity} reached (size: {$size})");
    }
}
