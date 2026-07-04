<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Platform\Http\Auth;

use Monadial\Nexus\Http\Auth\Authenticator\StaticTokenAuthenticator;
use Monadial\Nexus\Http\Auth\Principal\SimplePrincipal;

/**
 * Fixture identities for the example. Three tokens, two tenants, two
 * roles — enough to demonstrate tenant isolation and role guards.
 * Real deployments replace this with a JWT/OIDC Authenticator.
 */
final class DemoTokens
{
    public static function authenticator(): StaticTokenAuthenticator
    {
        return new StaticTokenAuthenticator([
            'acme-ops-token' => new SimplePrincipal(
                id: 'ops@acme',
                roles: ['ops'],
                scopes: [],
                claims: ['tenant' => 'acme'],
            ),
            'acme-picker-token' => new SimplePrincipal(
                id: 'picker@acme',
                roles: ['picker'],
                scopes: [],
                claims: ['tenant' => 'acme'],
            ),
            'umbrella-ops-token' => new SimplePrincipal(
                id: 'ops@umbrella',
                roles: ['ops'],
                scopes: [],
                claims: ['tenant' => 'umbrella'],
            ),
        ]);
    }
}
