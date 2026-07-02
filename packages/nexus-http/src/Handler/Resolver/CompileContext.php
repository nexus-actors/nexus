<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Handler\Resolver;

/**
 * @psalm-api
 *
 * Compile-time context passed to ParamResolver::compile(). Tells the resolver
 * which scope the parameter belongs to, who owns it (for error messages), and
 * which services are wired (container, serializer, actor table).
 *
 * `isRequestBound()` is the common gate: resolvers that need a request return
 * null in HttpBoot scope and proceed otherwise.
 */
final readonly class CompileContext
{
    public function __construct(public Scope $scope, public string $owner, public ResolverServices $services) {}

    public function isRequestBound(): bool
    {
        return $this->scope !== Scope::HttpBoot;
    }
}
