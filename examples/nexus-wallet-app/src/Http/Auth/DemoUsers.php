<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Http\Auth;

use Monadial\Nexus\Example\Wallet\Boot\WalletAuthConfig;
use Monadial\Nexus\Http\Auth\Authenticator\StaticTokenAuthenticator;
use Monadial\Nexus\Http\Auth\Principal;
use Monadial\Nexus\Http\Auth\Principal\SimplePrincipal;

use function array_pad;
use function explode;
use function trim;

/**
 * Hard-coded bearer-token → Principal map for the example.
 *
 * In production you'd swap this for JwtAuthenticator or a database-backed
 * one — the AuthenticationMiddleware contract is the same.
 *
 * Two token lists are parsed from the environment (see WalletAuthConfig):
 * `WALLET_AUTH_TOKENS` mints `user`-role principals (wallet:read/write) and
 * `WALLET_ADMIN_TOKENS` mints `admin`-role principals (also wallet:admin). The
 * lists are kept separate so an admin token can never be accidentally issued
 * from the user list. Each entry is `token=userId` (comma-separated).
 */
final readonly class DemoUsers
{
    public static function fromConfig(WalletAuthConfig $auth): StaticTokenAuthenticator
    {
        $tokenMap = [
            ...self::parse($auth->tokens, roles: ['user'], scopes: ['wallet:read', 'wallet:write']),
            ...self::parse(
                $auth->adminTokens,
                roles: ['admin'],
                scopes: ['wallet:read', 'wallet:write', 'wallet:admin'],
            ),
        ];

        return new StaticTokenAuthenticator($tokenMap);
    }

    /**
     * @param list<string> $roles
     * @param list<string> $scopes
     * @return array<string, Principal>
     */
    private static function parse(string $env, array $roles, array $scopes): array
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
                roles: $roles,
                scopes: $scopes,
                claims: ['source' => 'demo'],
            );
        }

        return $tokenMap;
    }
}
