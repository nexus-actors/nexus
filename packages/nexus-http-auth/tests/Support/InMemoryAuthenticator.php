<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Tests\Support;

use Monadial\Nexus\Http\Auth\Authenticator;
use Monadial\Nexus\Http\Auth\Authenticator\StaticTokenAuthenticator;
use Monadial\Nexus\Http\Auth\Extractor\BearerTokenExtractor;
use Monadial\Nexus\Http\Auth\Principal;
use Override;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @psalm-api
 *
 * Thin wrapper around StaticTokenAuthenticator with BearerTokenExtractor —
 * the canonical fixture for downstream package tests. Exported via
 * autoload-dev (tests/) so other packages can `use` it.
 */
final readonly class InMemoryAuthenticator implements Authenticator
{
    private StaticTokenAuthenticator $delegate;

    /** @param array<string, Principal> $tokenToPrincipal */
    public function __construct(array $tokenToPrincipal)
    {
        $this->delegate = new StaticTokenAuthenticator($tokenToPrincipal, new BearerTokenExtractor());
    }

    #[Override]
    public function authenticate(ServerRequestInterface $request): ?Principal
    {
        return $this->delegate->authenticate($request);
    }
}
