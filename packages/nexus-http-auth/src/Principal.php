<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth;

/**
 * @psalm-api
 *
 * The "who" of an authenticated request. Set by AuthenticationMiddleware
 * onto the PSR-7 request attribute "principal"; read by handlers via
 * #[FromPrincipal] or $req->getAttribute('principal').
 *
 * Implementations should be immutable readonly value objects. The default
 * SimplePrincipal covers 90% of cases; custom implementations let you carry
 * domain-specific identity (user objects, tenant ids, etc).
 */
interface Principal
{
    /**
     * Stable identifier for the principal — used for logging, audit, MDC.
     * Typically a user id, service account name, or "anonymous".
     */
    public function id(): string;

    /** @return list<string> */
    public function roles(): array;

    /** @return list<string> */
    public function scopes(): array;

    /** @return array<string, mixed> */
    public function claims(): array;

    public function hasRole(string $role): bool;

    public function hasScope(string $scope): bool;
}
