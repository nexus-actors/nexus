<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Authenticator;

use Monadial\Nexus\Http\Auth\Authenticator;
use Monadial\Nexus\Http\Auth\Principal;
use Override;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @psalm-api
 *
 * Tries authenticators in order, first non-null result wins. Exceptions
 * from inner authenticators propagate (configuration errors should not
 * be silenced).
 *
 *   new ChainAuthenticator([
 *       new JwtAuthenticator(...),     // primary
 *       new StaticTokenAuthenticator($devTokens),  // fallback for tests/dev
 *   ]);
 */
final readonly class ChainAuthenticator implements Authenticator
{
    /** @param list<Authenticator> $authenticators */
    public function __construct(private array $authenticators) {}

    #[Override]
    public function authenticate(ServerRequestInterface $request): ?Principal
    {
        foreach ($this->authenticators as $auth) {
            $principal = $auth->authenticate($request);

            if ($principal !== null) {
                return $principal;
            }
        }

        return null;
    }
}
