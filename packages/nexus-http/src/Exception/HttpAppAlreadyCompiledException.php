<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Exception;

use Monadial\Nexus\Core\Exception\NexusException;

/**
 * @psalm-api
 *
 * Thrown when the HttpApp DSL is mutated after compile(). Compilation freezes
 * the application: register all routes, actors, middleware, and configuration
 * before the first compile() call.
 */
final class HttpAppAlreadyCompiledException extends NexusException
{
    public function __construct(string $operation)
    {
        parent::__construct(
            "Cannot call {$operation} after compile(): HttpApp is frozen once compiled. "
            . 'Register all routes, actors, middleware, and configuration before compiling.',
        );
    }
}
