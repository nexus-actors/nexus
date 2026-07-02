<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Exception;

/**
 * @psalm-api
 *
 * No valid credentials presented. Mapped to 401 by AuthorizationMiddleware.
 * Users can re-map via $app->onException(Unauthenticated::class, ...) to
 * customise the response shape.
 */
final class Unauthenticated extends AuthException
{
    public function __construct(string $message = 'Authentication required')
    {
        parent::__construct($message);
    }
}
