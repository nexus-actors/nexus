<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Security;

/**
 * @psalm-api
 *
 * Marker interface for PSR-15 middleware that enforces the authorization
 * requirements declared via {@see AuthorizationRequirement} attributes
 * (e.g. nexus-http-auth's AuthorizationMiddleware).
 *
 * Compilation uses this marker to verify that every route whose handler
 * declares a requirement actually has an enforcer in its pipeline. Custom
 * authorization middleware should implement it to satisfy the compile-time
 * fail-closed check.
 */
interface AuthorizationEnforcer {}
