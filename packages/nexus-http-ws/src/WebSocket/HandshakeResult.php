<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Ws\WebSocket;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @psalm-api
 *
 * Outcome of evaluating a WebSocket upgrade request against the
 * {@see HandshakeGate} before the 101 switch. Either the connection is
 * authorized — carrying the request as mutated by the middleware pipeline
 * (path params, `_nexus.routed`, and the `principal` attribute stamped by
 * authentication middleware) — or it is rejected with the HTTP response the
 * pipeline produced (401/403/404/...), which must be sent instead of
 * upgrading.
 */
final readonly class HandshakeResult
{
    private function __construct(public ?ServerRequestInterface $request, public ?ResponseInterface $rejection) {}

    public static function authorized(ServerRequestInterface $request): self
    {
        return new self($request, null);
    }

    public static function rejected(ResponseInterface $response): self
    {
        return new self(null, $response);
    }

    /** @psalm-assert-if-true !null $this->request */
    public function isAuthorized(): bool
    {
        return $this->request !== null;
    }
}
