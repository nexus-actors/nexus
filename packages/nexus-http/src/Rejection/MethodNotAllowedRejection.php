<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Rejection;

/**
 * Raised when a path matched but the HTTP method did not. Maps to HTTP 405,
 * carrying the allowed-methods list for the `Allow` response header.
 */
final class MethodNotAllowedRejection extends RouteRejection
{
    /** @param list<string> $allowed */
    public function __construct(public readonly string $method, public readonly array $allowed)
    {
        parent::__construct(
            'method_not_allowed',
            "method '{$method}' not allowed; allowed: " . implode(', ', $allowed),
            405,
        );
    }
}
