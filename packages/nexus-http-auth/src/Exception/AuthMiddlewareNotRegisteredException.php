<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Exception;

/**
 * @psalm-api
 *
 * Thrown when a handler requests #[FromPrincipal] but the request has no
 * 'principal' attribute — meaning AuthenticationMiddleware was never
 * registered. Bubbles to 500 with a diagnostic hint at request time.
 */
final class AuthMiddlewareNotRegisteredException extends AuthException
{
    public static function forHandler(string $handlerClass): self
    {
        return new self(
            "{$handlerClass} requested #[FromPrincipal] but no Principal was found on the request. "
            . 'Register AuthenticationMiddleware globally on your application: '
            . '$app->middleware(new AuthenticationMiddleware($authenticator)).',
        );
    }
}
