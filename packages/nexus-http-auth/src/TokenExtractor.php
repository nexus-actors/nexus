<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth;

use Psr\Http\Message\ServerRequestInterface;

/**
 * @psalm-api
 *
 * Pulls a credential string out of the request. Returns null when the
 * request carries no token in this extractor's expected location.
 *
 * Built-in extractors: BearerTokenExtractor (Authorization: Bearer ...),
 * HeaderTokenExtractor (custom header), CookieTokenExtractor (signed cookie).
 */
interface TokenExtractor
{
    public function extract(ServerRequestInterface $request): ?string;
}
