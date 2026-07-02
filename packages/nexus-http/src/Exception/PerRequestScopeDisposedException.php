<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Exception;

use Monadial\Nexus\Core\Exception\NexusException;

/** @psalm-api */
final class PerRequestScopeDisposedException extends NexusException
{
    public function __construct(string $name)
    {
        parent::__construct("Cannot spawn per-request actor '{$name}': scope is already disposed.");
    }
}
