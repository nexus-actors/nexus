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
