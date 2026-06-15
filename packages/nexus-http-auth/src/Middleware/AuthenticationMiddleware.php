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
 * @psalm-api
 *
 * Runs an Authenticator and stamps the resulting Principal onto the
 * 'principal' request attribute. Never 401s — anonymous requests flow
 * through unchanged. AuthorizationMiddleware (per-route) is responsible
 * for the 401/403 decision based on route attributes.
 *
 * Register globally:
 *
 *   $app->middleware(new AuthenticationMiddleware($authenticator, $logger))
 *       ->get('/health', static fn() => Response::ok())          // public
 *       ->get('/orders', OrderListHandler::class);                // RequiresAuth on the class
 */
final class AuthenticationMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Authenticator $authenticator,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $principal = $this->authenticator->authenticate($request);

        if ($principal !== null) {
            $this->logger->debug('auth.principal.stamped', ['principalId' => $principal->id()]);
            $request = $request->withAttribute('principal', $principal);
        } else {
            $this->logger->debug('auth.anonymous');
        }

        return $handler->handle($request);
    }
}
