<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Exception;

/**
 * @psalm-api
 *
 * Thrown when AuthorizationMiddleware runs before the router has resolved the
 * target handler — i.e. it was registered globally instead of per-route. This
 * is a fail-closed guard: without the resolved handler class the middleware
 * cannot evaluate #[RequiresAuth]/#[RequiresScope]/#[RequiresRole]/#[Authorize],
 * so rather than silently letting the request through it aborts with a 500 and
 * a diagnostic hint. Bubbles to 500 at request time.
 */
final class AuthorizationMisconfiguredException extends AuthException
{
    public static function ranBeforeRouter(): self
    {
        return new self(
            'AuthorizationMiddleware ran before the router resolved a handler. '
            . 'It must be registered PER-ROUTE, not globally, so it runs after routing: '
            . '$app->get(\'/path\', Handler::class)->middleware(AuthorizationMiddleware::class).',
        );
    }
}
