<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Exception;

use Monadial\Nexus\Core\Exception\NexusException;

/** @psalm-api */
final class MissingEntityManagerScopeException extends NexusException
{
    public function __construct()
    {
        parent::__construct(
            'No EntityManagerLease found on the request. Did you install EntityManagerScopeMiddleware in the HTTP pipeline?',
        );
    }
}
