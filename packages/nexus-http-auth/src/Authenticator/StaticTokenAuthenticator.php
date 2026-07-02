<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Authenticator;

use Monadial\Nexus\Http\Auth\Authenticator;
use Monadial\Nexus\Http\Auth\Extractor\BearerTokenExtractor;
use Monadial\Nexus\Http\Auth\Principal;
use Monadial\Nexus\Http\Auth\TokenExtractor;
use Override;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @psalm-api
 *
 * Map<token, Principal>. Use in tests and fixtures — never in production.
 * No cryptographic verification, just a string-keyed lookup.
 *
 *   $auth = new StaticTokenAuthenticator([
 *       'k_alice' => new SimplePrincipal('alice', scopes: ['orders.read']),
 *       'k_admin' => new SimplePrincipal('admin', roles: ['admin']),
 *   ]);
 */
final readonly class StaticTokenAuthenticator implements Authenticator
{
    private TokenExtractor $extractor;

    /** @param array<string, Principal> $tokenToPrincipal */
    public function __construct(private array $tokenToPrincipal, ?TokenExtractor $extractor = null)
    {
        $this->extractor = $extractor ?? new BearerTokenExtractor();
    }

    #[Override]
    public function authenticate(ServerRequestInterface $request): ?Principal
    {
        $token = $this->extractor->extract($request);

        if ($token === null) {
            return null;
        }

        return $this->tokenToPrincipal[$token] ?? null;
    }
}
