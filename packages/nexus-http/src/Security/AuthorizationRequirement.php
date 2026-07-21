<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Security;

/**
 * @psalm-api
 *
 * Marker interface for PHP attributes that declare an authorization
 * requirement on a handler class (e.g. #[RequiresAuth], #[RequiresScope]).
 *
 * Application compilation fails closed: a route whose handler class carries
 * an attribute implementing this interface MUST have a middleware
 * implementing {@see AuthorizationEnforcer} registered on it, otherwise
 * compile() throws instead of serving the route unprotected.
 */
interface AuthorizationRequirement {}
