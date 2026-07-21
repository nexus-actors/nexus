<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Extractor;

use Monadial\Nexus\Http\Auth\TokenExtractor;
use Override;
use Psr\Http\Message\ServerRequestInterface;

use function is_string;

/**
 * @psalm-api
 *
 * Reads a token from a cookie. The cookie value is treated as-is — if you
 * use signed cookies, verify the signature inside the Authenticator.
 *
 * SECURITY: a cookie bearer token is attached by the browser to cross-site
 * requests and WebSocket upgrades automatically, so on its own it is
 * vulnerable to CSRF and cross-site WebSocket hijacking. When you set the
 * cookie, mark it `HttpOnly`, `Secure`, and `SameSite=Strict` (or `Lax`), and
 * protect state-changing routes and WebSocket upgrades with
 * `OriginAllowlistMiddleware` (and a CSRF token for defense in depth). This
 * extractor only reads the value; it cannot enforce those properties.
 */
final readonly class CookieTokenExtractor implements TokenExtractor
{
    public function __construct(private string $cookieName) {}

    #[Override]
    public function extract(ServerRequestInterface $request): ?string
    {
        $cookies = $request->getCookieParams();

        if (!isset($cookies[$this->cookieName])) {
            return null;
        }

        /** @var mixed $value */
        $value = $cookies[$this->cookieName];

        return is_string($value)
            ? $value
            : null;
    }
}
