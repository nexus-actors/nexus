<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Extractor;

use Monadial\Nexus\Http\Auth\TokenExtractor;
use Override;
use Psr\Http\Message\ServerRequestInterface;

use function preg_match;

/**
 * @psalm-api
 *
 * Extracts the token from `Authorization: Bearer <token>`. The scheme
 * match is case-insensitive (per RFC 7235); the token itself preserves case.
 */
final class BearerTokenExtractor implements TokenExtractor
{
    private const string AUTH_HEADER_REGEX = '/^Bearer\s+(\S+)\s*$/i';

    #[Override]
    public function extract(ServerRequestInterface $request): ?string
    {
        $header = $request->getHeaderLine('Authorization');

        if ($header === '') {
            return null;
        }

        if (preg_match(self::AUTH_HEADER_REGEX, $header, $m) !== 1) {
            return null;
        }

        return $m[1];
    }
}
