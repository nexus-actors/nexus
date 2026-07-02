<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Principal;

use Monadial\Nexus\Http\Auth\Principal;
use Override;

use function in_array;

/**
 * @psalm-api
 *
 * Default Principal implementation — readonly value object carrying id,
 * roles, scopes, and arbitrary claims. The default target for JwtAuthenticator's
 * claims-mapper.
 *
 * For domain-specific identity (User entity, tenant context), implement
 * Principal directly instead.
 */
final readonly class SimplePrincipal implements Principal
{
    /**
     * @param list<string> $roles
     * @param list<string> $scopes
     * @param array<string, mixed> $claims
     */
    public function __construct(
        private string $id,
        private array $roles = [],
        private array $scopes = [],
        private array $claims = [],
    ) {}

    #[Override]
    public function id(): string
    {
        return $this->id;
    }

    #[Override]
    public function roles(): array
    {
        return $this->roles;
    }

    #[Override]
    public function scopes(): array
    {
        return $this->scopes;
    }

    #[Override]
    public function claims(): array
    {
        return $this->claims;
    }

    #[Override]
    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    #[Override]
    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }
}
