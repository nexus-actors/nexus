<?php
declare(strict_types=1);

namespace Monadial\Nexus\Core\Exception;

/** @psalm-api */
final class InvalidActorPathException extends NexusLogicException
{
    public function __construct(public readonly string $invalidPath)
    {
        parent::__construct("Invalid actor path: '{$invalidPath}'");
    }
}
