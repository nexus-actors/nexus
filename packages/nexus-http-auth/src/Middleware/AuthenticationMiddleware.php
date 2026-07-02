<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Middleware;

use Monadial\Nexus\Http\Auth\Authenticator;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * PSR-15 middleware that authenticates every inbound request.
 *
 * Delegates authentication to an {@see Authenticator} and, if credentials are
 * valid, stamps the resulting `Principal` onto the `'principal'` request
 * attribute for downstream handlers to consume. Anonymous requests (no or
 * unrecognised credentials) flow through unchanged — this middleware never
 * produces a 401 response by itself. The 401/403 decision is made downstream
 * by `AuthorizationMiddleware`, which inspects route-level PHP attributes such
 * as {@see RequiresAuth}, `RequiresRole`, and `RequiresScope`.
 *
 * Additionally, the boolean flag {@see CHECKED_ATTRIBUTE} is set on every
 * request that passes through. Downstream resolvers use this flag to distinguish
 * "no credentials supplied" (401) from "middleware was never registered" (500).
 *
 * Register once globally so every route is covered:
 * ```php
 * $app->middleware(new AuthenticationMiddleware($authenticator, $logger))
 *     ->get('/health', static fn() => Response::ok())   // public route
 *     ->get('/orders', OrderListHandler::class);         // #[RequiresAuth] on class
 * ```
 *
 * @see Authenticator          Strategy interface that maps a request to a Principal
 * @see RequiresAuth           Route-level attribute that enforces authentication
 *
 * @psalm-api
 */
final readonly class AuthenticationMiddleware implements MiddlewareInterface
{
    /**
     * Request attribute set unconditionally on every passage through this middleware.
     *
     * Resolvers and downstream middleware read this flag to distinguish
     * "middleware ran, no credentials" (should 401) from "middleware was never
     * registered" (likely a configuration error that should 500).
     */
    public const string CHECKED_ATTRIBUTE = 'nexus.auth.checked';

    public function __construct(
        private Authenticator $authenticator,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Authenticate the request and pass it to the next handler.
     *
     * Sets {@see CHECKED_ATTRIBUTE} on the request, then attempts authentication.
     * On success the `Principal` is added as the `'principal'` attribute.
     * Anonymous requests pass through with only the checked flag set.
     */
    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $principal = $this->authenticator->authenticate($request);
        $request = $request->withAttribute(self::CHECKED_ATTRIBUTE, true);

        if ($principal !== null) {
            $this->logger->debug('auth.principal.stamped', ['principalId' => $principal->id()]);
            $request = $request->withAttribute('principal', $principal);
        } else {
            $this->logger->debug('auth.anonymous');
        }

        return $handler->handle($request);
    }
}
