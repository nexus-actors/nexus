<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Extractor;

use Monadial\Nexus\Http\Auth\TokenExtractor;
use Override;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @psalm-api
 *
 * Reads the raw value of a configurable header. Useful for X-Api-Key /
 * X-Auth-Token style schemes where there's no scheme prefix.
 */
final readonly class HeaderTokenExtractor implements TokenExtractor
{
    public function __construct(private string $headerName) {}

    #[Override]
    public function extract(ServerRequestInterface $request): ?string
    {
        $value = $request->getHeaderLine($this->headerName);

        return $value === ''
            ? null
            : $value;
    }
}
