<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Exception;

use Monadial\Nexus\Http\Auth\Authorizer;

/**
 * @psalm-api
 *
 * Thrown at compile time when #[Authorize(X::class)] references a class
 * that doesn't implement Authorizer. Fails the build, not the request.
 */
final class InvalidAuthorizerException extends AuthException
{
    public static function notAnAuthorizer(string $class): self
    {
        return new self("#[Authorize({$class}::class)] — {$class} must implement " . Authorizer::class . '.');
    }
}
