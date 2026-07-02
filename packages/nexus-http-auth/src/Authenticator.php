<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth;

use Psr\Http\Message\ServerRequestInterface;

/**
 * @psalm-api
 *
 * Verifies request credentials and returns a Principal, or null for
 * anonymous. Implementations MUST swallow all credential validation
 * failures (bad signature, expired, malformed) and return null —
 * never throw on a bad token. Exceptions are reserved for configuration
 * errors (missing key, broken backend).
 *
 * The middleware never 401s based on null — that decision belongs to
 * AuthorizationMiddleware based on route attributes.
 */
interface Authenticator
{
    public function authenticate(ServerRequestInterface $request): ?Principal;
}
