<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Security;

use Closure;
use ReflectionClass;

use function class_exists;
use function explode;
use function is_a;
use function is_string;
use function str_contains;

/**
 * @psalm-api
 *
 * Compile-time inspection for the fail-closed authorization check: which
 * handler classes declare {@see AuthorizationRequirement} attributes, and
 * whether a middleware list contains an {@see AuthorizationEnforcer}.
 */
final class RouteProtection
{
    /**
     * The first authorization-requirement attribute declared on the handler
     * class, or null when the handler declares none (closures cannot carry
     * class attributes; unloadable classes are reported by handler resolution
     * with its own error).
     *
     * @param string|Closure $handler Class name, 'Class::method' string, or Closure.
     * @return ?class-string
     */
    public static function requirementOf(string|Closure $handler): ?string
    {
        if ($handler instanceof Closure) {
            return null;
        }

        $class = str_contains($handler, '::')
            ? explode('::', $handler, 2)[0]
            : $handler;

        if (!class_exists($class)) {
            return null;
        }

        foreach (new ReflectionClass($class)->getAttributes() as $attribute) {
            if (is_a($attribute->getName(), AuthorizationRequirement::class, true)) {
                return $attribute->getName();
            }
        }

        return null;
    }

    /**
     * Whether the middleware list contains an authorization enforcer, given
     * either as an instance or as a class-string.
     *
     * @param iterable<mixed> $middleware
     */
    public static function hasEnforcer(iterable $middleware): bool
    {
        /** @var mixed $entry */
        foreach ($middleware as $entry) {
            if ($entry instanceof AuthorizationEnforcer) {
                return true;
            }

            if (is_string($entry) && is_a($entry, AuthorizationEnforcer::class, true)) {
                return true;
            }
        }

        return false;
    }
}
