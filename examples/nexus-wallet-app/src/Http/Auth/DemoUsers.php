<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Http\Auth;

use Monadial\Nexus\Http\Auth\Authenticator\StaticTokenAuthenticator;
use Monadial\Nexus\Http\Auth\Principal\SimplePrincipal;

/**
 * Hard-coded bearer-token → Principal map for the example.
 *
 * In production you'd swap this for JwtAuthenticator or a database-backed
 * one — the AuthenticationMiddleware contract is the same.
 *
 * Tokens are parsed from the WALLET_AUTH_TOKENS env var as
 * "token1=user1,token2=user2,…" so docker-compose can vary them per env.
 */
final readonly class DemoUsers
{
    public static function fromEnv(string $env): StaticTokenAuthenticator
    {
        $tokenMap = [];

        foreach (explode(',', $env) as $pair) {
            $pair = trim($pair);

            if ($pair === '') {
                continue;
            }

            [$token, $userId] = array_pad(explode('=', $pair, 2), 2, '');

            if ($token === '' || $userId === '') {
                continue;
            }

            $tokenMap[$token] = new SimplePrincipal(
                id: $userId,
                roles: ['user'],
                scopes: ['wallet:read', 'wallet:write'],
                claims: ['source' => 'demo'],
            );
        }

        return new StaticTokenAuthenticator($tokenMap);
    }
}
