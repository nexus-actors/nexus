<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Rejection;

/**
 * Raised when the request body cannot be parsed (invalid JSON, malformed form,
 * etc.). Maps to HTTP 400.
 */
final class BodyParseException extends RouteRejection
{
    public function __construct(string $reason)
    {
        parent::__construct('body_parse_failed', $reason, 400);
    }
}
