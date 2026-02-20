<?php
declare(strict_types=1);

namespace Monadial\Nexus\Core\Exception;

use Monadial\Nexus\Core\Actor\ActorPath;

/** @psalm-api */
final class ActorNameExistsException extends NexusLogicException
{
    public function __construct(public readonly ActorPath $parent, public readonly string $duplicateName)
    {
        parent::__construct("Actor name '{$duplicateName}' already exists under {$parent}");
    }
}
