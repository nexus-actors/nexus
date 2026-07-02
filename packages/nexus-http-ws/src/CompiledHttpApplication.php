<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Ws;

use Monadial\Nexus\Http\App\CompiledHttpApp;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** @psalm-api */
final readonly class CompiledHttpApplication implements CompiledApplication
{
    public function __construct(private CompiledHttpApp $http) {}

    #[Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->http->handle($request);
    }

    #[Override]
    public function hasWebSocketRoutes(): bool
    {
        return false;
    }

    public function inner(): CompiledHttpApp
    {
        return $this->http;
    }
}
