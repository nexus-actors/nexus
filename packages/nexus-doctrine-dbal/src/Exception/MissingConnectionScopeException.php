<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Exception;

use Monadial\Nexus\Core\Exception\NexusException;

/** @psalm-api */
final class MissingConnectionScopeException extends NexusException
{
    public function __construct()
    {
        parent::__construct(
            'No ConnectionLease found on the request. Did you install ConnectionScopeMiddleware in the HTTP pipeline?',
        );
    }
}
