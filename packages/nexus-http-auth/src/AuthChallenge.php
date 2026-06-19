<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth;

use function str_replace;

/**
 * @psalm-api
 *
 * RFC 7235 WWW-Authenticate challenge. Returned with 401 responses so the
 * client knows what credentials to present. realm and error are optional.
 *
 * Standard error codes (RFC 6750): "invalid_token", "insufficient_scope".
 */
final readonly class AuthChallenge
{
    public function __construct(public string $scheme, public ?string $realm = null, public ?string $error = null) {}

    public function toHeader(): string
    {
        $parts = [$this->scheme];

        if ($this->realm !== null) {
            $parts[0] = $this->scheme . ' realm="' . str_replace('"', '\\"', $this->realm) . '"';
        }

        if ($this->error !== null) {
            $parts[] = 'error="' . str_replace('"', '\\"', $this->error) . '"';
        }

        return implode(', ', $parts);
    }
}
