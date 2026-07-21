<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Toolkit\Middleware;

use Nyholm\Psr7\Factory\Psr17Factory;
use Override;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function in_array;
use function strtoupper;

/**
 * @psalm-api
 *
 * Rejects requests whose `Origin` header is not in an exact allow-list. The
 * primary defense against cross-site WebSocket hijacking (CSWSH) and
 * cookie-based CSRF: the browser attaches cookies to a cross-site WebSocket
 * upgrade or form POST automatically, and neither has CORS preflight
 * protection, so the server must verify the request came from an origin it
 * trusts.
 *
 * Matching is EXACT (scheme + host + port), never a substring or wildcard —
 * `https://app.example.com` does not match `https://app.example.com.evil.com`
 * or `http://app.example.com`. A request with no `Origin` header is allowed by
 * default (non-browser clients, same-origin navigations that omit it) but this
 * can be tightened with `allowMissingOrigin: false` for endpoints reachable
 * only from browsers carrying cookie credentials.
 *
 * Use it as WebSocket middleware (`wsMiddleware()`, so it runs in the
 * pre-upgrade handshake) and on state-changing HTTP routes:
 *
 *   $app->wsMiddleware(new OriginAllowlistMiddleware(['https://app.example.com']));
 *   $app->post('/transfer', TransferHandler::class)
 *       ->middleware(new OriginAllowlistMiddleware(['https://app.example.com']));
 *
 * Pair cookie bearer tokens with `SameSite=Strict`/`Lax` and this check; a
 * CSRF token remains advisable for defense in depth.
 */
final readonly class OriginAllowlistMiddleware implements MiddlewareInterface
{
    private ResponseFactoryInterface $responseFactory;

    /**
     * @param list<string> $allowedOrigins Exact origins (scheme://host[:port]) to accept.
     * @param bool $allowMissingOrigin When true (default), a request with no Origin
     *        header passes; set false to require an allowed Origin on every request.
     * @param list<string> $safeMethods HTTP methods exempt from the check (safe,
     *        non-state-changing). WebSocket upgrades arrive as GET, so GET is NOT
     *        exempt by default — the check applies to upgrades and to unsafe methods.
     */
    public function __construct(
        private array $allowedOrigins,
        private bool $allowMissingOrigin = true,
        private array $safeMethods = [],
        ?ResponseFactoryInterface $responseFactory = null,
    ) {
        $this->responseFactory = $responseFactory ?? new Psr17Factory();
    }

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (in_array(strtoupper($request->getMethod()), $this->safeMethods, true)) {
            return $handler->handle($request);
        }

        $origin = $request->getHeaderLine('Origin');

        if ($origin === '') {
            return $this->allowMissingOrigin
                ? $handler->handle($request)
                : $this->responseFactory->createResponse(403);
        }

        // Exact match only — no normalization, no suffix/prefix matching.
        if (!in_array($origin, $this->allowedOrigins, true)) {
            return $this->responseFactory->createResponse(403);
        }

        return $handler->handle($request);
    }
}
