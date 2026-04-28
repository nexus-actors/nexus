<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Rejection;

/**
 * Raised when an extractor (path parameter, query, header, body) fails to
 * decode the request fragment into the expected type. Maps to HTTP 400.
 */
final class ExtractorRejection extends RouteRejection
{
    public function __construct(string $where, string $reason)
    {
        parent::__construct('extractor_failed', "{$where}: {$reason}", 400);
    }
}
